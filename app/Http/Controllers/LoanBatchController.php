<?php

namespace App\Http\Controllers;

use App\LoanEvidence;
use App\Models\User;
use App\RecordLoanDisbursement;
use App\RecordLoanRepayment;
use App\RecordMemberFundTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class LoanBatchController extends Controller
{
    public function index(): View
    {
        $batches = DB::table('loan_batches')->orderByDesc('id')->simplePaginate(25);

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

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:disbursement,repayment'],
            'loan_ids' => ['required_if:kind,disbursement', 'array', 'max:100'],
            'loan_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'period' => ['required_if:kind,repayment', 'date_format:Y-m', 'after_or_equal:1900-01', 'before_or_equal:2089-12'],
            'allocations' => ['nullable', 'file', 'max:1024'],
            'contributions' => ['nullable', 'file', 'max:1024'],
        ]);

        $repayment = $data['kind'] === 'repayment';
        $allocations = $repayment && isset($data['allocations']) ? $this->readAllocations($data['allocations']) : [];
        $contributions = $repayment && isset($data['contributions']) ? $this->readContributions($data['contributions']) : [];
        if ($repayment && $allocations === [] && $contributions === []) {
            $this->invalid('Upload the loan repayment CSV, the savings and shares CSV, or both.');
        }
        $ids = $repayment ? array_keys($allocations) : $data['loan_ids'];
        $eligible = $repayment ? 'disbursed' : 'approved';

        $batch = DB::transaction(function () use ($request, $data, $repayment, $allocations, $contributions, $ids, $eligible): int {
            $members = DB::table('members')->whereIn('registration_no', array_keys($contributions))->orderBy('id')
                ->lockForUpdate()->get(['id', 'registration_no', 'status']);
            if ($members->count() !== count($contributions) || $members->contains(fn ($member): bool => $member->status !== 'active')) {
                $this->invalid('Every contribution must match an active member by membership number.');
            }
            $loans = DB::table('loan_applications')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($loans->count() !== count($ids) || $loans->contains(fn ($loan): bool => $loan->status !== $eligible)) {
                $this->invalid("Every loan on this list must be $eligible.");
            }

            if (! $repayment && DB::table('member_exits')->whereIn('member_id', $loans->pluck('member_id'))
                ->whereIn('status', ['requested', 'approved'])->exists()) {
                $this->invalid('A selected borrower has an open membership exit.');
            }

            $reserved = DB::table('loan_batches')->where('kind', $data['kind'])->whereIn('status', ['prepared', 'approved'])
                ->where('period', $repayment ? $data['period'].'-01' : null)->pluck('items')
                ->flatMap(fn (string $items): array => json_decode($items, true, flags: JSON_THROW_ON_ERROR));
            if ($reserved->pluck('loan_id')->filter()->intersect($ids)->isNotEmpty()) {
                $this->invalid('A selected loan already appears on an open list for this workflow and period.');
            }
            if ($reserved->filter(fn (array $item): bool => isset($item['funds']))->pluck('member_id')
                ->intersect($members->pluck('id'))->isNotEmpty()) {
                $this->invalid('A selected member already has contributions on an open list for this period.');
            }

            $items = [];
            foreach ($loans as $loan) {
                $item = ['loan_id' => $loan->id, 'member_id' => $loan->member_id];
                if ($repayment) {
                    $planned = $allocations[$loan->id];
                    $installments = DB::table('loan_application_installments')->where('loan_application_id', $loan->id)->get()->keyBy('number');
                    $paid = DB::table('loan_repayment_allocations')->where('loan_application_id', $loan->id)
                        ->selectRaw('installment_number, sum(principal_minor) AS principal, sum(interest_minor) AS interest')
                        ->groupBy('installment_number')->get()->keyBy('installment_number');

                    foreach ($planned as $number => $amounts) {
                        $installment = $installments->get($number);
                        $prior = $paid->get($number);
                        if ($installment === null
                            || $amounts['principal'] > $installment->principal_minor - (int) ($prior->principal ?? 0)
                            || $amounts['interest'] > $installment->interest_minor - (int) ($prior->interest ?? 0)) {
                            $this->invalid("Loan {$loan->id}, installment $number exceeds its remaining amount or does not exist.");
                        }
                    }
                    $item['allocations'] = $planned;
                    $item['amount'] = array_sum(array_map('array_sum', $planned));
                } else {
                    $item['amount'] = $loan->principal_minor;
                }
                $items[] = $item;
            }

            $verified = DB::table('member_verified_balances')->whereIn('member_id', $members->pluck('id'))
                ->distinct()->pluck('member_id');
            foreach ($members as $member) {
                if (! $verified->contains($member->id)) {
                    $this->invalid("Record verified opening balances for member {$member->registration_no}.");
                }
                $funds = $contributions[$member->registration_no];
                $items[] = ['member_id' => $member->id, 'funds' => $funds, 'amount' => array_sum($funds)];
            }

            return DB::table('loan_batches')->insertGetId([
                'kind' => $data['kind'],
                'period' => $repayment ? $data['period'].'-01' : null,
                'items' => json_encode($items, JSON_THROW_ON_ERROR),
                'status' => 'prepared',
                'prepared_by' => $request->user()->id,
                'prepared_at' => now(),
            ]);
        }, 3);

        return redirect()->route('staff.loan-batches.show', $batch);
    }

    public function show(int $batch): View
    {
        $record = DB::table('loan_batches')->find($batch);
        abort_if($record === null, 404);
        $items = json_decode($record->items, true, flags: JSON_THROW_ON_ERROR);
        $members = DB::table('members')->whereIn('id', array_column($items, 'member_id'))
            ->get(['id', 'registration_no', 'first_name', 'last_name'])->keyBy('id');
        $actors = DB::table('users')->whereIn('id', array_filter([$record->prepared_by, $record->reviewed_by, $record->confirmed_by]))
            ->pluck('name', 'id');

        $fundTransactions = DB::table('member_fund_transactions')->where('loan_batch_id', $batch)
            ->orderBy('id')->get(['id', 'member_id', 'kind', 'signed_minor']);

        return view('staff.loan-batches.show', compact('record', 'items', 'members', 'actors', 'fundTransactions'));
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

    public function confirm(Request $request, int $batch, RecordLoanDisbursement $transfers, RecordLoanRepayment $repayments, LoanEvidence $evidence, RecordMemberFundTransaction $funds): RedirectResponse
    {
        $data = $request->validate([
            'effective_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Africa/Dar_es_Salaam')->toDateString()],
            'bank_checklist' => ['nullable', 'file', 'mimes:pdf', 'max:8192'],
            'references' => ['nullable', 'array', 'max:100'],
            'references.*' => ['required', 'string', 'max:100'],
            'payroll_reference' => ['nullable', 'string', 'max:100'],
        ]);

        DB::transaction(function () use ($request, $batch, $data, $transfers, $repayments, $evidence, $funds): void {
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

            $members = DB::table('members')->whereIn('id', array_column($items, 'member_id'))
                ->orderBy('id')->lockForUpdate()->get(['id', 'status'])->keyBy('id');
            DB::table('loan_applications')->whereIn('id', array_column($items, 'loan_id'))->orderBy('id')->lockForUpdate()->get(['id']);
            $actor = $request->user()->id;
            foreach ($items as $item) {
                if (isset($item['funds'])) {
                    $member = $members->get($item['member_id']);
                    $opening = DB::table('member_verified_balances')->where('member_id', $member->id)
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
            ]);
        }, 3);

        return redirect()->route('staff.loan-batches.show', $batch)->with('status', 'Approved list confirmed.');
    }

    public function repaymentTemplate(): Response
    {
        return response()->download(base_path('templates/loan-import/monthly_repayment_list.csv'), 'monthly_repayment_list.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function contributionTemplate(): Response
    {
        return response()->download(base_path('templates/loan-import/monthly_contributions.csv'), 'monthly_contributions.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function checklist(int $batch, LoanEvidence $evidence): Response
    {
        $id = DB::table('loan_batches')->where('kind', 'disbursement')->where('id', $batch)->value('evidence_id');
        abort_if($id === null, 404);

        return $evidence->download($id, "bank-checklist-$batch.pdf");
    }

    private function readAllocations(UploadedFile $upload): array
    {
        $file = $upload->openFile();
        $file->setCsvControl(',', '"', '');
        $header = $file->fgetcsv() ?: [];
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0] ?? '');
        if ($header !== ['application_id', 'installment_number', 'principal_tzs', 'interest_tzs']) {
            $this->invalid('Use the monthly repayment CSV headers shown on the form.');
        }

        $loans = [];
        $count = 0;
        while (! $file->eof()) {
            $row = $file->fgetcsv();
            if ($row === false || $row === [null]) {
                continue;
            }
            if (count($row) !== 4 || ++$count > 500) {
                $this->invalid('Use four columns and no more than 500 allocations.');
            }

            [$id, $installment, $principal, $interest] = array_map('trim', $row);
            if (! preg_match('/\A[1-9]\d{0,9}\z/', $id) || ! preg_match('/\A[1-9]\d{0,2}\z/', $installment)
                || (int) $id > 2147483647 || (int) $installment > 120 || isset($loans[(int) $id][(int) $installment])) {
                $this->invalid('Each CSV row must identify a unique application and installment.');
            }
            $principalMinor = $this->minorUnits($principal);
            $interestMinor = $this->minorUnits($interest);
            if ($principalMinor + $interestMinor === 0) {
                $this->invalid('Each allocation must contain a positive principal or interest amount.');
            }

            $loans[(int) $id][(int) $installment] = ['principal' => $principalMinor, 'interest' => $interestMinor];
        }

        if ($loans === [] || count($loans) > 100) {
            $this->invalid('Include allocations for 1 to 100 loans.');
        }

        return $loans;
    }

    private function readContributions(UploadedFile $upload): array
    {
        $file = $upload->openFile();
        $file->setCsvControl(',', '"', '');
        $header = $file->fgetcsv() ?: [];
        $header[0] = preg_replace('/^\\xEF\\xBB\\xBF/', '', $header[0] ?? '');
        if ($header !== ['registration_no', 'savings_tzs', 'shares_tzs']) {
            $this->invalid('Use the monthly contributions CSV template.');
        }

        $members = [];
        while (! $file->eof()) {
            $row = $file->fgetcsv();
            if ($row === false || $row === [null]) {
                continue;
            }
            if (count($row) !== 3 || count($members) >= 500) {
                $this->invalid('Use three contribution columns and no more than 500 members.');
            }
            [$number, $savings, $shares] = array_map('trim', $row);
            $number = Str::upper($number);
            if (! preg_match('/\\A[A-Z0-9-]{1,30}\\z/', $number) || isset($members[$number])) {
                $this->invalid('Each contribution row must have a unique membership number.');
            }

            $amounts = [
                'savings_deposit' => $this->minorUnits($savings),
                'share_purchase' => $this->minorUnits($shares),
            ];
            if (array_sum($amounts) === 0) {
                $this->invalid('Each contribution row must include a positive savings or shares amount.');
            }
            $members[$number] = $amounts;
        }

        if ($members === []) {
            $this->invalid('The savings and shares CSV has no contribution records.');
        }

        return $members;
    }

    private function minorUnits(string $amount): int
    {
        if (! preg_match('/\A\d{1,12}(?:\.\d{1,2})?\z/', $amount)) {
            $this->invalid('Use nonnegative TZS amounts with up to two decimal places.');
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['batch' => $message]);
    }
}
