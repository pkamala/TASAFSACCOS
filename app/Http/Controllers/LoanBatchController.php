<?php

namespace App\Http\Controllers;

use App\LedgerPeriods;
use App\LoanEvidence;
use App\LoanTopUp;
use App\Models\User;
use App\MonthlyPayroll;
use App\PayrollDeductions;
use App\RecordLoanDisbursement;
use App\RecordLoanRepayment;
use App\RecordMemberFundTransaction;
use App\TablePage;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

class LoanBatchController extends Controller
{
    public function index(): View
    {
        $batches = DB::table('loan_batches')->where('kind', '<>', 'exit_settlement')->orderByDesc('id')
            ->tap(TablePage::search(['id', 'kind', 'period', 'status', 'effective_date', 'review_note']))
            ->paginate(TablePage::size());

        return view('staff.loan-batches.index', compact('batches'));
    }

    public function create(): View
    {
        $loans = DB::table('loan_applications as loan')->join('members as member', 'member.id', '=', 'loan.member_id')
            ->where('loan.status', 'approved')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('member_exits')
                ->whereColumn('member_exits.member_id', 'loan.member_id')->whereIn('member_exits.status', ['requested', 'approved']))
            ->orderBy('loan.id')
            ->get(['loan.id', 'loan.principal_minor', 'member.registration_no', 'member.first_name', 'member.last_name']);

        return view('staff.loan-batches.create', compact('loans'));
    }

    public function store(Request $request, MonthlyPayroll $payroll): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:disbursement,repayment'],
            'loan_ids' => ['required_if:kind,disbursement', 'array', 'max:100'],
            'loan_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'period' => ['required_if:kind,repayment', 'date_format:Y-m', 'after_or_equal:1900-01', 'before_or_equal:2089-12'],
            'include_overdue' => ['sometimes', 'boolean'],
            'allocations' => ['prohibited'],
            'contributions' => ['prohibited'],
        ]);

        $actor = $request->user()->id;
        $batch = $data['kind'] === 'repayment'
            ? $payroll->prepare($data['period'], $actor, (bool) ($data['include_overdue'] ?? false))
            : $this->prepareDisbursement($data['loan_ids'], $actor);

        return redirect()->route('staff.loan-batches.show', $batch);
    }

    private function prepareDisbursement(array $ids, int $actor): int
    {
        return DB::transaction(function () use ($ids, $actor): int {
            $loans = DB::table('loan_applications')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($loans->count() !== count($ids) || $loans->contains(fn ($loan): bool => $loan->status !== 'approved')) {
                $this->invalid('Every loan on this list must be approved.');
            }
            if (DB::table('member_exits')->whereIn('member_id', $loans->pluck('member_id'))
                ->whereIn('status', ['requested', 'approved'])->exists()) {
                $this->invalid('A selected borrower has an open membership exit.');
            }

            $reserved = DB::table('loan_batches')->where('kind', 'disbursement')->whereIn('status', ['prepared', 'approved'])
                ->pluck('items')->flatMap(fn (string $items): array => json_decode($items, true, flags: JSON_THROW_ON_ERROR));
            if ($reserved->pluck('loan_id')->intersect($ids)->isNotEmpty()) {
                $this->invalid('A selected loan already appears on an open disbursement list.');
            }

            $items = $loans->map(fn ($loan): array => ['loan_id' => $loan->id, 'member_id' => $loan->member_id, 'amount' => $loan->principal_minor]);

            return DB::table('loan_batches')->insertGetId([
                'kind' => 'disbursement', 'items' => json_encode($items, JSON_THROW_ON_ERROR),
                'status' => 'prepared', 'prepared_by' => $actor, 'prepared_at' => now(),
            ]);
        }, 3);
    }

    public function show(Request $request, int $batch, PayrollDeductions $deductions, LoanTopUp $topUps): View|RedirectResponse
    {
        $record = DB::table('loan_batches')->find($batch);
        abort_if($record === null, 404);
        if ($record->kind === 'exit_settlement') {
            $exit = DB::table('member_exits')->where('settlement_batch_id', $batch)->sole();

            return redirect()->route('staff.member-exits.voucher', $exit->id);
        }
        $items = json_decode($record->items, true, flags: JSON_THROW_ON_ERROR);
        $members = DB::table('members')->whereIn('id', array_column($items, 'member_id'))
            ->get(['id', 'registration_no', 'first_name', 'last_name'])->keyBy('id');
        $actors = DB::table('users')->whereIn('id', array_filter([$record->prepared_by, $record->reviewed_by, $record->confirmed_by]))
            ->pluck('name', 'id');

        $fundTransactions = DB::table('member_fund_transactions')->where('loan_batch_id', $batch)
            ->whereIn('kind', ['savings_deposit', 'share_purchase'])
            ->orderBy('id')->get(['id', 'member_id', 'kind'])
            ->groupBy('member_id')->map(fn ($transactions) => $transactions->keyBy('kind'));

        $deductionReview = json_decode($record->deduction_review ?? 'null', true, flags: JSON_THROW_ON_ERROR);
        $deductionDifferences = collect($deductionReview === null ? [] : $deductions->differences($deductions->components($items), $deductionReview['overrides']))
            ->groupBy(fn (array $row): string => isset($row['loan_id']) ? 'loan:'.$row['loan_id'] : 'fund:'.$row['member_id']);
        $confirmedItems = $deductionReview === null ? $items : PayrollDeductions::confirmedItems($record);
        $confirmedTotal = $deductionReview === null ? null : array_sum(array_column($confirmedItems, 'amount'));
        $payrollTotals = null;
        $pendingLoanDeductions = 0;
        if ($record->kind === 'repayment' && $record->status === 'confirmed') {
            $confirmed = collect($confirmedItems);
            $payrollTotals = [
                'savings' => $confirmed->sum('funds.savings_deposit'),
                'shares' => $confirmed->sum('funds.share_purchase'),
                'loans' => $confirmed->whereNotNull('loan_id')->sum('amount'),
            ];
            if ($record->period !== null && $request->user()->can('manage-members')) {
                $pendingLoanDeductions = DB::table('payroll_loan_reconciliations')
                    ->where('payroll_month', $record->period)->whereNull('receipt_id')->count();
            }
        }

        $topUpTransfers = [];
        if ($record->kind === 'disbursement') {
            $topUpTransfers = DB::table('loan_applications as loan')->whereIn('loan.id', array_column($items, 'loan_id'))
                ->whereNotNull('loan.top_up_loan_id')
                ->leftJoin('loan_disbursements as transfer', 'transfer.loan_application_id', '=', 'loan.id')
                ->get(['loan.id', 'loan.top_up_loan_id', 'loan.principal_minor', 'transfer.cash_amount_minor'])
                ->mapWithKeys(function ($loan) use ($topUps): array {
                    $payoff = $loan->cash_amount_minor !== null
                        ? $loan->principal_minor - $loan->cash_amount_minor
                        : $topUps->quote($loan->top_up_loan_id, now('Africa/Dar_es_Salaam')->toDateString())['total'];

                    return [$loan->id => ['loan' => $loan->top_up_loan_id, 'payoff' => $payoff, 'net' => $loan->principal_minor - $payoff]];
                })->all();
        }
        $netTransferTotal = $topUpTransfers === [] ? null
            : array_sum(array_column($items, 'amount')) - array_sum(array_column($topUpTransfers, 'payoff'));

        return view('staff.loan-batches.show', compact(
            'record', 'items', 'members', 'actors', 'fundTransactions', 'deductionReview', 'deductionDifferences', 'confirmedTotal',
            'payrollTotals', 'pendingLoanDeductions', 'topUpTransfers', 'netTransferTotal',
        ));
    }

    public function review(Request $request, int $batch): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,declined'],
            'review_note' => ['required', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($request, $batch, $data): void {
            DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtext(?))', ['board-roles']);
            abort_unless(User::query()->findOrFail($request->user()->id)->isBoardOfficer(), 403);
            $record = DB::table('loan_batches')->lockForUpdate()->find($batch);
            abort_if($record === null, 404);
            abort_if($record->prepared_by === $request->user()->id, 403);
            if ($record->status !== 'prepared') {
                $this->invalid('Only a prepared list can be reviewed.');
            }

            DB::table('loan_batches')->where('id', $batch)->update([
                'status' => $data['decision'],
                'review_note' => $data['review_note'],
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
        });

        return redirect()->route('staff.loan-batches.show', $batch)->with('status', 'Review decision recorded.');
    }

    public function confirm(Request $request, int $batch, RecordLoanDisbursement $transfers, RecordLoanRepayment $repayments, LoanEvidence $evidence, RecordMemberFundTransaction $funds, LedgerPeriods $periods, PayrollDeductions $deductions): RedirectResponse
    {
        $data = $request->validate([
            'effective_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Africa/Dar_es_Salaam')->toDateString()],
            'bank_checklist' => ['nullable', 'file', 'mimes:pdf', 'max:8192'],
            'references' => ['nullable', 'array', 'max:100'],
            'references.*' => ['required', 'string', 'max:100'],
            'net_transfers' => ['nullable', 'array', 'max:100'],
            'net_transfers.*' => ['required', 'numeric', 'decimal:0,2', 'between:0.01,100000000000'],
            'payroll_reference' => ['nullable', 'string', 'max:100'],
            'deduction_token' => ['nullable', 'string', 'size:40'],
            'deduction_note' => ['nullable', 'string', 'max:500'],
            'confirm_exact' => ['sometimes', 'accepted'],
        ]);

        DB::transaction(function () use ($request, $batch, $data, $transfers, $repayments, $evidence, $funds, $periods, $deductions): void {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['payroll-schedule']);
            $record = DB::table('loan_batches')->lockForUpdate()->find($batch);
            abort_if($record === null, 404);
            if ($record->status !== 'approved') {
                $this->invalid('The Chairperson or Secretary must approve the list before confirmation.');
            }
            $reviewedOn = CarbonImmutable::parse($record->reviewed_at)->setTimezone('Africa/Dar_es_Salaam')->toDateString();
            if ($data['effective_date'] < $reviewedOn) {
                $this->invalid('The effective date cannot be before approval.');
            }

            $items = json_decode($record->items, true, flags: JSON_THROW_ON_ERROR);
            $disbursement = $record->kind === 'disbursement';
            if ($disbursement && (! isset($data['bank_checklist']) || count($data['references'] ?? []) !== count($items))) {
                $this->invalid('Attach the bank-stamped checklist and provide one transfer reference per loan.');
            }
            if (! $disbursement && ! isset($data['payroll_reference'])) {
                $this->invalid('Provide the confirmed TASAF Accounts payroll reference.');
            }

            $deductionReview = $disbursement ? null : $this->deductionReview($request, $data, $record, $items, $deductions);
            if ($deductionReview !== null) {
                $items = PayrollDeductions::apply($items, $deductionReview['overrides']);
                if ($items === []) {
                    $periods->requireOpenDates([$data['effective_date']], 'effective_date');
                }
            }

            $members = DB::table('members')->whereIn('id', array_column($items, 'member_id'))
                ->orderBy('id')->lockForUpdate()->get(['id', 'status'])->keyBy('id');
            DB::table('loan_applications')->whereIn('id', array_column($items, 'loan_id'))->orderBy('id')->lockForUpdate()->get(['id']);
            $actor = $request->user()->id;
            foreach ($items as $item) {
                if (isset($item['funds'])) {
                    $member = $members->get($item['member_id']);
                    $opening = DB::table('member_verified_balances')->whereNull('superseded_by_id')->where('member_id', $member->id)
                        ->orderByDesc('as_of')->orderByDesc('id')->first();
                    if ($member->status !== 'active' || $opening === null || $data['effective_date'] < $opening->as_of) {
                        $this->invalid('Contributions require active membership and a verified opening balance dated on or before the payroll transaction.');
                    }

                    foreach (array_filter($item['funds']) as $kind => $amount) {
                        $funds->record($actor, $member->id, $opening->id, [
                            'reference' => $data['payroll_reference'], 'effective_on' => $data['effective_date'],
                            'note' => "TASAF payroll list $batch",
                        ], $kind, $amount, batch: $batch);
                    }

                    continue;
                }

                if ($disbursement) {
                    $transfers->record([
                        'transfer_reference' => $data['references'][$item['loan_id']] ?? null,
                        'transferred_on' => $data['effective_date'],
                        'transfer_note' => "Bank checklist $batch",
                        'net_transfer' => $data['net_transfers'][$item['loan_id']] ?? null,
                    ], $actor, $item['member_id'], $item['loan_id'], $batch);
                } else {
                    if (DB::table('loan_repayment_receipts')->where('loan_application_id', $item['loan_id'])
                        ->where('receipt_reference', Str::upper($data['payroll_reference']))->exists()) {
                        $this->invalid("The payroll reference is already recorded for loan {$item['loan_id']}.");
                    }
                    $money = static fn (int $minor): string => number_format($minor / 100, 2, '.', '');
                    $repayments->record([
                        'receipt_reference' => $data['payroll_reference'],
                        'received_on' => $data['effective_date'],
                        'amount' => $money($item['amount']),
                        'allocations' => array_map(fn (array $entry): array => array_map($money, $entry), $item['allocations']),
                    ], $actor, $item['member_id'], $item['loan_id'], $batch);
                }
            }

            DB::table('loan_batches')->where('id', $batch)->update([
                'status' => 'confirmed',
                'confirmed_by' => $actor,
                'confirmed_at' => now(),
                'effective_date' => $data['effective_date'],
                'evidence_id' => isset($data['bank_checklist']) ? $evidence->record($data['bank_checklist']) : null,
                'deduction_review' => $deductionReview === null ? null : json_encode($deductionReview, JSON_THROW_ON_ERROR),
            ]);
        }, 3);

        if ($request->session()->get('payroll_deduction_preview.batch') === $batch) {
            $request->session()->forget('payroll_deduction_preview');
        }

        return redirect()->route('staff.loan-batches.show', $batch)->with('status', 'Approved list confirmed.');
    }

    private function deductionReview(Request $request, array $data, stdClass $record, array $items, PayrollDeductions $deductions): ?array
    {
        $preview = $request->session()->get('payroll_deduction_preview');
        $hasPreview = ($preview['batch'] ?? null) === $record->id && ($preview['actor'] ?? null) === $request->user()->id;
        $token = $data['deduction_token'] ?? null;
        if ($token === null && ! $hasPreview) {
            return isset($data['confirm_exact']) ? [
                'overrides' => [], 'source_sha256' => null, 'reference' => $data['payroll_reference'], 'note' => null,
            ] : null;
        }
        if ($token === null || ! $hasPreview || ! hash_equals($preview['token'], $token)
            || ! hash_equals($preview['approved_sha256'], hash('sha256', $record->items))) {
            throw ValidationException::withMessages(['deductions' => 'Preview the actual deductions again before confirming this payroll.']);
        }

        $differences = collect($deductions->differences($deductions->components($items), $preview['overrides']));
        if ($differences->contains('status', 'Excess')) {
            throw ValidationException::withMessages(['deductions' => 'Resolve excess deductions with TASAF Accounts before confirming payroll.']);
        }
        if ($differences->isNotEmpty() && ! isset($data['deduction_note'])) {
            throw ValidationException::withMessages(['deduction_note' => 'Record a finance review note for the missed or partial deductions.']);
        }

        return [
            'overrides' => $preview['overrides'], 'source_sha256' => $preview['source_sha256'],
            'reference' => $data['payroll_reference'], 'note' => $data['deduction_note'] ?? null,
        ];
    }

    public function checklist(int $batch, LoanEvidence $evidence): Response
    {
        $id = DB::table('loan_batches')->where('kind', 'disbursement')->where('id', $batch)->value('evidence_id');
        abort_if($id === null, 404);

        return $evidence->download($id, "bank-checklist-$batch.pdf");
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['batch' => $message]);
    }
}
