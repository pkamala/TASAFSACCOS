<?php

namespace App\Http\Controllers;

use App\LoanApproval;
use App\LoanEligibility;
use App\LoanEvidence;
use App\LoanPricing;
use App\LoanSchedule;
use App\LoanSponsorship;
use App\LoanTopUp;
use App\Models\User;
use App\TablePage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

class LoanApplicationController extends Controller
{
    public function queue(Request $request, LoanApproval $approval, LoanSponsorship $sponsorship): View
    {
        $actor = $request->user();
        $query = DB::table('loan_applications as application')
            ->where('application.status', 'submitted')
            ->join('members as member', 'member.id', '=', 'application.member_id')
            ->join('users as creator', 'creator.id', '=', 'application.created_by')
            ->leftJoin('loan_categories as category', 'category.id', '=', 'application.loan_category_id')
            ->select([
                'application.id', 'application.member_id', 'application.principal_minor', 'application.approval_lane',
                'category.name as category_name', 'member.registration_no', 'member.first_name', 'member.last_name', 'creator.name as creator',
            ])->addSelect([
                'confirmed_sponsors' => $sponsorship->confirmedActiveCount('application'),
                'board_borrower' => $approval->boardBorrower(DB::table('users'))
                    ->whereColumn('users.member_id', 'application.member_id')->selectRaw('true')->limit(1),
            ]);
        if (! $actor->is_staff_admin) {
            if ($actor->can('review-loan-applications')) {
                $query->where(function ($visible) use ($approval, $actor): void {
                    $visible->where(fn ($reviewable) => $approval->forReviewer($reviewable, $actor, 'application'));
                    if ($actor->hasLoanRole('staff')) {
                        $visible->orWhere('application.created_by', $actor->id);
                    }
                });
            } else {
                $query->where('application.created_by', $actor->id);
            }
        }
        $applications = $query->orderByRaw('lower(member.first_name), lower(member.last_name)')->orderBy('application.id')
            ->tap(TablePage::search(['application.id', 'application.approval_lane', 'category.name', 'member.registration_no', 'member.first_name', 'member.last_name', 'creator.name']))
            ->paginate(TablePage::size());

        return view('staff.loan-applications.queue', compact('applications'));
    }

    public function index(int $member): View
    {
        $profile = $this->member($member);
        $applications = DB::table('loan_applications as application')->where('application.member_id', $member)
            ->leftJoin('loan_categories as category', 'category.id', '=', 'application.loan_category_id')
            ->orderByDesc('application.id')
            ->tap(TablePage::search(['application.id', 'application.status', 'application.created_at', 'category.name']))
            ->paginate(TablePage::size(), ['application.*', 'category.name as category_name']);

        return view('staff.loan-applications.index', compact('profile', 'applications'));
    }

    public function create(Request $request, LoanPricing $prices, ?int $member = null): View
    {
        $selfService = $member === null;
        $member ??= $this->ownMember($request);
        $profile = $this->member($member);
        abort_unless($profile->status === 'active', 403);

        $selected = $request->validate(['category' => ['nullable', 'integer', 'exists:loan_categories,id']]);
        $categories = $prices->categories();
        $category = (int) ($selected['category'] ?? $categories->first()->id);

        return view('staff.loan-applications.create', [
            'selfService' => $selfService, 'profile' => $profile, 'submissionId' => (string) Str::uuid(),
            'categories' => $categories, 'category' => $category,
            'otherActiveLoans' => DB::table('loan_applications')->where('member_id', $member)->where('status', 'disbursed')->count(),
            'pricing' => $prices->current($category),
            'topUpLoans' => DB::table('loan_applications')->where('member_id', $member)
                ->where('loan_category_id', $category)->where('status', 'disbursed')->orderBy('id')->pluck('id'),
        ]);
    }

    public function store(Request $request, LoanSchedule $calculator, LoanEligibility $eligibility, LoanPricing $prices, LoanApproval $approval, LoanSponsorship $sponsorship, LoanTopUp $topUps, ?int $member = null): RedirectResponse
    {
        $selfService = $member === null;
        $member ??= $this->ownMember($request);
        $data = $request->validate([
            ...$sponsorship->rules(),
            'submission_id' => ['required', 'uuid'],
            'principal' => ['required', 'numeric', 'decimal:0,2', 'between:100,100000000000'],
            'pricing_revision_id' => ['required', 'integer', 'min:1'],
            'loan_category_id' => ['required', 'integer', 'exists:loan_categories,id'],
            'top_up_loan_id' => ['nullable', 'integer', 'min:1'],
            'insurance' => ['prohibited'],
            'processing_fee' => ['prohibited'],
            'other_charges' => ['prohibited'],
            'annual_rate' => ['prohibited'],
            'interest_method' => ['prohibited'],
            'term_months' => ['required', 'integer', 'between:1,120'],
            'first_due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now('Africa/Dar_es_Salaam')->toDateString(), 'before_or_equal:2089-12-31'],
        ]);
        $actor = $request->user()->id;

        $id = DB::transaction(function () use ($data, $member, $actor, $calculator, $eligibility, $prices, $approval, $sponsorship, $topUps): int {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['loan-application:'.Str::lower($data['submission_id'])]);
            DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtext(?))', ['board-roles']);
            $profile = DB::table('members')->lockForUpdate()->find($member);
            abort_if($profile === null, 404);
            $existing = DB::table('loan_applications')->where('submission_id', $data['submission_id'])->first();
            if ($existing !== null) {
                abort_unless($existing->member_id === $member && $existing->created_by === $actor, 409);

                return $existing->id;
            }

            if ($profile->status !== 'active') {
                throw ValidationException::withMessages(['member' => 'A new application requires an active member.']);
            }

            $category = (int) $data['loan_category_id'];
            $prices->lock($category);
            $pricing = $prices->current($category);
            if ($pricing === null || $pricing->id !== (int) $data['pricing_revision_id']) {
                throw ValidationException::withMessages(['pricing_revision_id' => 'Review the current pricing for the selected category before submitting.']);
            }

            $principal = (int) round((float) $data['principal'] * 100);
            $amounts = [
                'principal_minor' => $principal,
                'insurance_minor' => intdiv($principal * $pricing->insurance_basis_points + 5000, 10000),
                'processing_fee_minor' => $pricing->processing_fee_minor,
                'other_charges_minor' => $pricing->other_charges_minor,
            ];
            $source = isset($data['top_up_loan_id']) ? $topUps->source((int) $data['top_up_loan_id'], $member, $category) : null;
            if ($source && $principal <= $topUps->quote($source->id, now('Africa/Dar_es_Salaam')->toDateString())['total']) {
                throw ValidationException::withMessages(['principal' => 'The new principal must exceed the original loan payoff.']);
            }
            $qualification = $eligibility->requireEligible($member, array_sum($amounts), $category, replaces: $source?->id);
            $schedule = $calculator->calculate(
                array_sum($amounts), $pricing->annual_basis_points, (int) $data['term_months'], $pricing->interest_method, $data['first_due_date'],
            );

            $application = DB::table('loan_applications')->insertGetId([
                ...$amounts,
                'submission_id' => $data['submission_id'],
                'member_id' => $member,
                'created_by' => $actor,
                'annual_basis_points' => $pricing->annual_basis_points,
                'loan_pricing_revision_id' => $pricing->id,
                'loan_category_id' => $data['loan_category_id'],
                'top_up_loan_id' => $source?->id,
                'loan_eligibility_revision_id' => $qualification['minimums']->id,
                'member_verified_balance_id' => $qualification['balance']->id,
                'eligible_savings_minor' => $qualification['funds']['available'],
                'eligible_shares_minor' => $qualification['funds']['shares'],
                'term_months' => $data['term_months'],
                'interest_method' => $pricing->interest_method,
                'first_due_date' => $data['first_due_date'],
                'status' => 'submitted',
                'approval_lane' => $approval->borrowerLane($member) === 'board' ? 'pending_board' : 'pending',
                'created_at' => now(),
            ]);

            $sponsorship->nominate($application, $member, $data['sponsors']);

            DB::table('loan_application_installments')->insert(array_map(static fn (array $row): array => [
                'loan_application_id' => $application,
                'number' => $row['month'],
                'due_date' => $row['date'],
                'opening_minor' => $row['opening'],
                'principal_minor' => $row['principal'],
                'interest_minor' => $row['interest'],
                'payment_minor' => $row['payment'],
                'closing_minor' => $row['closing'],
            ], $schedule['rows']));

            return $application;
        }, 3);

        if ($selfService) {
            return redirect()->route('member.account')->withFragment('member-loans-heading')
                ->with('status', "Loan application $id submitted. Both sponsors need to confirm before it can be forwarded.");
        }

        return redirect()->route('staff.loan-applications.show', [$member, $id]);
    }

    public function show(Request $request, int $member, int $application, LoanApproval $approval, LoanSponsorship $sponsorship): View
    {
        $profile = $this->member($member);
        $record = DB::table('loan_applications as application')->where('application.member_id', $member)
            ->join('users as creator', 'creator.id', '=', 'application.created_by')
            ->leftJoin('loan_categories as category', 'category.id', '=', 'application.loan_category_id')
            ->leftJoin('users as officer', 'officer.id', '=', 'application.recommended_by')
            ->where('application.id', $application)
            ->first(['application.*', 'creator.name as creator', 'category.name as category_name', 'officer.name as loan_officer']);
        abort_if($record === null || ! $approval->mayView($request->user(), $record), 404);

        $installments = DB::table('loan_application_installments')->where('loan_application_id', $application)->orderBy('number')->get();
        $decision = DB::table('loan_application_decisions as decision')
            ->join('users as reviewer', 'reviewer.id', '=', 'decision.reviewed_by')
            ->where('loan_application_id', $application)->first(['decision.*', 'reviewer.name as reviewer']);
        $disbursement = DB::table('loan_disbursements as transfer')
            ->join('users as recorder', 'recorder.id', '=', 'transfer.recorded_by')
            ->where('loan_application_id', $application)->first(['transfer.*', 'recorder.name as recorder']);
        $migration = DB::table('active_loan_migrations')->where('loan_application_id', $application)->first();
        $paid = DB::table('loan_repayment_allocations')->where('loan_application_id', $application)
            ->selectRaw('installment_number, sum(principal_minor) AS principal_minor, sum(interest_minor) AS interest_minor')
            ->groupBy('installment_number')->get()->keyBy('installment_number');
        $receipts = DB::table('loan_repayment_receipts as receipt')
            ->join('users as recorder', 'recorder.id', '=', 'receipt.recorded_by')
            ->where('loan_application_id', $application)
            ->select('receipt.*', 'recorder.name as recorder')
            ->orderByDesc('receipt.id')
            ->tap(TablePage::search(['receipt.received_on', 'receipt.receipt_reference', 'recorder.name'], 'receipts_page'))
            ->paginate(TablePage::size('receipts_page'), ['*'], 'receipts_page');
        $receiptAllocations = DB::table('loan_repayment_allocations')
            ->whereIn('loan_repayment_receipt_id', $receipts->getCollection()->pluck('id'))
            ->orderBy('installment_number')->get()->groupBy('loan_repayment_receipt_id');
        $cancellation = DB::table('loan_exit_cancellations as cancellation')
            ->join('users as officer', 'officer.id', '=', 'cancellation.cancelled_by')
            ->where('cancellation.loan_application_id', $application)
            ->first(['cancellation.*', 'officer.name as actor']);
        $mayCancelForExit = $record->status === 'approved' && $disbursement === null
            && $this->reviewerMayDecide($request->user(), $member)
            && DB::table('member_exits')->where('member_id', $member)->where('status', 'approved')->exists();
        $mayReview = $record->status === 'submitted' && $approval->mayReview($request->user(), $record);

        $sponsors = $sponsorship->forApplication($application);
        $mayEditSponsors = $request->user()->is_staff_admin && $sponsorship->mayChange($record);
        $sponsorsConfirmed = $sponsorship->ready($sponsors);
        $mayRecommend = $mayEditSponsors && $sponsorsConfirmed;
        $requiresMinutes = $record->approval_lane !== 'committee' || $approval->borrowerLane($member) === 'board';

        $otherActiveLoans = DB::table('loan_applications')->where('member_id', $member)->where('status', 'disbursed')
            ->where('id', '<>', $application)->count();

        return view('staff.loan-applications.show', compact('otherActiveLoans', 'profile', 'record', 'installments', 'decision', 'disbursement', 'migration', 'mayReview', 'paid', 'receipts', 'receiptAllocations', 'cancellation', 'mayCancelForExit', 'mayRecommend', 'mayEditSponsors', 'sponsors', 'sponsorsConfirmed', 'requiresMinutes'));
    }

    public function recommend(Request $request, int $member, int $application, LoanApproval $approval, LoanSponsorship $sponsorship): RedirectResponse
    {
        $data = $request->validate([
            'recommendation' => ['required', 'in:approve,decline'],
            'recommendation_note' => ['required', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $member, $application, $approval, $data, $sponsorship): void {
            DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtext(?))', ['board-roles']);
            $profile = DB::table('members')->lockForUpdate()->find($member);
            abort_if($profile === null, 404);
            $record = DB::table('loan_applications')->where('member_id', $member)->lockForUpdate()->find($application);
            abort_if($record === null, 404);
            if (! $sponsorship->mayChange($record)) {
                throw ValidationException::withMessages(['recommendation' => 'This application has already been forwarded or decided.']);
            }

            if (! $sponsorship->ready($sponsorship->forApplication($application, lock: true))) {
                throw ValidationException::withMessages(['sponsors' => 'Both active sponsors must confirm before the application can be forwarded.']);
            }

            DB::table('loan_applications')->where('id', $application)->update([
                ...$data,
                'approval_lane' => $record->approval_lane === 'pending_board' ? 'board' : $approval->borrowerLane($member),
                'recommended_by' => $request->user()->id,
                'recommended_at' => now(),
            ]);
        }, 3);

        return redirect()->route('staff.loan-applications.show', [$member, $application])->with('status', 'Recommendation saved and application forwarded.');
    }

    public function review(Request $request, int $member, int $application, LoanEvidence $evidence, LoanEligibility $eligibility, LoanApproval $approval, LoanTopUp $topUps): RedirectResponse
    {
        $actor = $request->user()->id;

        DB::transaction(function () use ($request, $member, $application, $actor, $evidence, $eligibility, $approval, $topUps): void {
            DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtext(?))', ['board-roles']);
            $reviewer = User::query()->findOrFail($actor);
            $profile = DB::table('members')->lockForUpdate()->find($member);
            abort_if($profile === null, 404);
            $record = DB::table('loan_applications')->where('member_id', $member)->lockForUpdate()->find($application);
            abort_if($record === null, 404);
            abort_unless($approval->mayReview($reviewer, $record), 403);

            $data = $request->validate([
                'decision' => ['required', 'in:approved,declined'],
                'reason' => ['required', 'string', 'max:500'],
                'minutes' => ['nullable', 'file', 'mimes:pdf', 'max:8192'],
            ]);

            if ($record->status !== 'submitted' || ($data['decision'] === 'approved' && $profile->status !== 'active')) {
                throw ValidationException::withMessages(['decision' => 'This application is no longer eligible for that decision.']);
            }

            if (($record->approval_lane !== 'committee' || $approval->borrowerLane($member) === 'board') && empty($data['minutes'])) {
                throw ValidationException::withMessages(['minutes' => 'Upload the board meeting minutes for this decision.']);
            }

            if ($data['decision'] === 'approved') {
                if ($record->top_up_loan_id !== null) {
                    $source = $topUps->source($record->top_up_loan_id, $member, $record->loan_category_id);
                    if ($record->principal_minor <= $topUps->quote($source->id, now('Africa/Dar_es_Salaam')->toDateString())['total']) {
                        throw ValidationException::withMessages(['principal' => 'The new principal must exceed the original loan payoff.']);
                    }
                }
                if ($record->loan_eligibility_revision_id !== null) {
                    $eligibility->requireEligible(
                        $member,
                        $record->principal_minor + $record->insurance_minor + $record->processing_fee_minor + $record->other_charges_minor,
                        $record->loan_category_id,
                        $application,
                        $record->top_up_loan_id,
                    );
                } else {
                    if (DB::table('member_exits')->where('member_id', $member)->whereIn('status', ['requested', 'approved'])->exists()) {
                        throw ValidationException::withMessages(['eligibility' => 'The member has an open exit request.']);
                    }
                    $eligibility->requireAvailableCategory($member, $record->loan_category_id, $application, $record->top_up_loan_id);
                }
            }

            DB::table('loan_application_decisions')->insert([
                'loan_application_id' => $application,
                'reviewed_by' => $actor,
                'decision' => $data['decision'],
                'reason' => $data['reason'],
                'reviewed_at' => now(),
                'minutes_id' => isset($data['minutes']) ? $evidence->record($data['minutes']) : null,
            ]);
            DB::table('loan_applications')->where('id', $application)->update(['status' => $data['decision']]);
        }, 3);

        return redirect()->route('staff.loan-applications.show', [$member, $application])->with('status', 'Loan decision recorded.');
    }

    public function cancelForExit(Request $request, int $member, int $application): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        DB::transaction(function () use ($request, $member, $application, $data): void {
            DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtext(?))', ['board-roles']);
            $reviewer = User::query()->findOrFail($request->user()->id);
            abort_unless($this->reviewerMayDecide($reviewer, $member), 403);
            abort_if(DB::table('members')->lockForUpdate()->find($member) === null, 404);
            $record = DB::table('loan_applications')->where('member_id', $member)->lockForUpdate()->find($application);
            abort_if($record === null, 404);
            $exit = DB::table('member_exits')->where('member_id', $member)->where('status', 'approved')->value('id');
            if ($record->status !== 'approved' || $exit === null
                || DB::table('loan_disbursements')->where('loan_application_id', $application)->exists()) {
                throw ValidationException::withMessages(['reason' => 'Only an undisbursed approved loan for an approved membership exit can be cancelled here.']);
            }

            DB::table('loan_exit_cancellations')->insert([
                'loan_application_id' => $application, 'member_exit_id' => $exit,
                'cancelled_by' => $reviewer->id, 'reason' => $data['reason'], 'cancelled_at' => now(),
            ]);
            DB::table('loan_applications')->where('id', $application)->update(['status' => 'cancelled']);
        }, 3);

        return redirect()->route('staff.loan-applications.show', [$member, $application])->with('status', 'Undisbursed loan cancelled for the member exit.');
    }

    public function voucher(Request $request, int $member, int $application, LoanApproval $approval): View
    {
        $record = DB::table('loan_applications')->where('member_id', $member)->whereNull('top_up_loan_id')->find($application);
        abort_if($record === null || ! $approval->mayView($request->user(), $record), 404);
        $transfer = DB::table('loan_disbursements as transfer')->join('users as actor', 'actor.id', '=', 'transfer.recorded_by')
            ->where('transfer.loan_application_id', $application)->first(['transfer.*', 'actor.name as recorder']);
        abort_if($transfer === null, 404);
        $profile = $this->member($member);
        $posting = DB::table('loan_journal_postings')->where('loan_batch_id', $transfer->loan_batch_id)->first();
        $reversed = $posting && DB::table('ledger_journal_reversals')->where('loan_journal_posting_id', $posting->id)->exists();

        return view('staff.loan-applications.voucher', compact('record', 'transfer', 'profile', 'posting', 'reversed'));
    }

    public function topUpVoucher(Request $request, int $member, int $application, LoanApproval $approval): View
    {
        $record = DB::table('loan_applications')->where('member_id', $member)->whereNotNull('top_up_loan_id')->find($application);
        abort_if($record === null || ! $approval->mayView($request->user(), $record), 404);
        $transfer = DB::table('loan_disbursements')->where('loan_application_id', $application)->first();
        abort_if($transfer === null, 404);
        $payoff = DB::table('loan_repayment_receipts as receipt')
            ->join('loan_repayment_allocations as allocation', 'allocation.loan_repayment_receipt_id', '=', 'receipt.id')
            ->where('receipt.loan_batch_id', $transfer->loan_batch_id)->where('receipt.loan_application_id', $record->top_up_loan_id)
            ->groupBy('receipt.id')->selectRaw('receipt.receipt_reference, sum(allocation.principal_minor) as principal, sum(allocation.interest_minor) as interest')->first();
        abort_if($payoff === null, 404);
        $profile = $this->member($member);

        return view('staff.loan-applications.top-up-voucher', compact('record', 'transfer', 'payoff', 'profile'));
    }

    public function minutes(Request $request, int $member, int $application, LoanEvidence $evidence, LoanApproval $approval): Response
    {
        $record = DB::table('loan_applications')->where('member_id', $member)->find($application);
        abort_if($record === null || ! $approval->mayView($request->user(), $record), 404);
        $id = DB::table('loan_application_decisions as decision')
            ->join('loan_applications as application', 'application.id', '=', 'decision.loan_application_id')
            ->where('application.id', $application)->where('application.member_id', $member)->value('minutes_id');
        abort_if($id === null, 404);

        return $evidence->download($id, "review-minutes-$application.pdf");
    }

    private function reviewerMayDecide(User $reviewer, int $member): bool
    {
        if (! $reviewer->isBoardOfficer() || $reviewer->member_id === $member) {
            return false;
        }

        $borrowerRole = DB::table('users')->where('member_id', $member)
            ->where('is_staff_admin', false)->whereIn('board_role', ['chairperson', 'secretary'])
            ->value('board_role');

        return match ($borrowerRole) {
            'chairperson' => $reviewer->board_role === 'secretary',
            'secretary' => $reviewer->board_role === 'chairperson',
            default => true,
        };
    }

    private function ownMember(Request $request): int
    {
        $user = $request->user();
        abort_if($user->member_id === null || $user->is_staff_admin, 403);

        return $user->member_id;
    }

    private function member(int $id): stdClass
    {
        $profile = DB::table('members')->find($id, ['id', 'first_name', 'last_name', 'registration_no', 'status']);
        abort_if($profile === null, 404);

        return $profile;
    }
}
