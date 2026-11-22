<?php

namespace App\Http\Controllers;

use App\LoanEligibility;
use App\LoanEvidence;
use App\LoanSchedule;
use App\Models\User;
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
    public function queue(): View
    {
        $applications = DB::table('loan_applications as application')
            ->where('application.status', 'submitted')
            ->join('members as member', 'member.id', '=', 'application.member_id')
            ->join('users as creator', 'creator.id', '=', 'application.created_by')
            ->select([
                'application.id', 'application.member_id', 'application.principal_minor',
                'member.registration_no', 'member.first_name', 'member.last_name', 'creator.name as creator',
            ])
            ->orderBy('application.id')->simplePaginate(25);

        return view('staff.loan-applications.queue', compact('applications'));
    }

    public function index(int $member): View
    {
        $profile = $this->member($member);
        $applications = DB::table('loan_applications')->where('member_id', $member)
            ->orderByDesc('id')->simplePaginate(25);

        return view('staff.loan-applications.index', compact('profile', 'applications'));
    }

    public function create(int $member, LoanEligibility $eligibility): View
    {
        $profile = $this->member($member);
        abort_unless($profile->status === 'active', 403);

        return view('staff.loan-applications.create', ['profile' => $profile, 'submissionId' => (string) Str::uuid(), 'eligibility' => $eligibility->details($member), 'pricing' => DB::table('loan_pricing_revisions')->orderByDesc('id')->first()]);
    }

    public function store(Request $request, int $member, LoanSchedule $calculator, LoanEligibility $eligibility): RedirectResponse
    {
        $data = $request->validate([
            'submission_id' => ['required', 'uuid'],
            'principal' => ['required', 'numeric', 'decimal:0,2', 'between:100,100000000000'],
            'pricing_revision_id' => ['required', 'integer', 'min:1'],
            'insurance' => ['prohibited'],
            'processing_fee' => ['prohibited'],
            'other_charges' => ['prohibited'],
            'annual_rate' => ['prohibited'],
            'interest_method' => ['prohibited'],
            'term_months' => ['required', 'integer', 'between:1,120'],
            'first_due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now('Africa/Dar_es_Salaam')->toDateString(), 'before_or_equal:2089-12-31'],
        ]);
        $actor = $request->user()->id;

        $id = DB::transaction(function () use ($data, $member, $actor, $calculator, $eligibility): int {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['loan-application:'.Str::lower($data['submission_id'])]);
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

            $qualification = $eligibility->requireEligible($member);

            DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtext(?))', ['loan-pricing']);
            $pricing = DB::table('loan_pricing_revisions')->orderByDesc('id')->first();
            if ($pricing === null || $pricing->id !== (int) $data['pricing_revision_id']) {
                throw ValidationException::withMessages(['pricing_revision_id' => 'Review the current loan pricing before submitting.']);
            }

            $principal = (int) round((float) $data['principal'] * 100);
            $amounts = [
                'principal_minor' => $principal,
                'insurance_minor' => intdiv($principal * $pricing->insurance_basis_points + 5000, 10000),
                'processing_fee_minor' => $pricing->processing_fee_minor,
                'other_charges_minor' => $pricing->other_charges_minor,
            ];
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
                'loan_eligibility_revision_id' => $qualification['minimums']->id,
                'member_verified_balance_id' => $qualification['balance']->id,
                'eligible_savings_minor' => $qualification['funds']['available'],
                'eligible_shares_minor' => $qualification['funds']['shares'],
                'term_months' => $data['term_months'],
                'interest_method' => $pricing->interest_method,
                'first_due_date' => $data['first_due_date'],
                'status' => 'submitted',
                'created_at' => now(),
            ]);

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

        return redirect()->route('staff.loan-applications.show', [$member, $id]);
    }

    public function show(Request $request, int $member, int $application): View
    {
        $profile = $this->member($member);
        $record = DB::table('loan_applications as application')->where('application.member_id', $member)
            ->join('users as creator', 'creator.id', '=', 'application.created_by')
            ->where('application.id', $application)
            ->first(['application.*', 'creator.name as creator']);
        abort_if($record === null, 404);

        $installments = DB::table('loan_application_installments')->where('loan_application_id', $application)->orderBy('number')->get();
        $decision = DB::table('loan_application_decisions as decision')
            ->join('users as reviewer', 'reviewer.id', '=', 'decision.reviewed_by')
            ->where('loan_application_id', $application)->first(['decision.*', 'reviewer.name as reviewer']);
        $disbursement = DB::table('loan_disbursements as transfer')
            ->join('users as recorder', 'recorder.id', '=', 'transfer.recorded_by')
            ->where('loan_application_id', $application)->first(['transfer.*', 'recorder.name as recorder']);
        $paid = DB::table('loan_repayment_allocations')->where('loan_application_id', $application)
            ->selectRaw('installment_number, sum(principal_minor) AS principal_minor, sum(interest_minor) AS interest_minor')
            ->groupBy('installment_number')->get()->keyBy('installment_number');
        $receipts = DB::table('loan_repayment_receipts as receipt')
            ->join('users as recorder', 'recorder.id', '=', 'receipt.recorded_by')
            ->where('loan_application_id', $application)
            ->select('receipt.*', 'recorder.name as recorder')
            ->orderByDesc('receipt.id')->simplePaginate(15, ['*'], 'receipts_page');
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
        $mayReview = $record->status === 'submitted' && $this->reviewerMayDecide($request->user(), $member) && $request->user()->id !== $record->created_by;

        return view('staff.loan-applications.show', compact('profile', 'record', 'installments', 'decision', 'disbursement', 'mayReview', 'paid', 'receipts', 'receiptAllocations', 'cancellation', 'mayCancelForExit'));
    }

    public function review(Request $request, int $member, int $application, LoanEvidence $evidence, LoanEligibility $eligibility): RedirectResponse
    {
        $reviewer = $request->user();
        abort_unless($this->reviewerMayDecide($reviewer, $member), 403);

        $data = $request->validate([
            'decision' => ['required', 'in:approved,declined'],
            'reason' => ['required', 'string', 'max:500'],
            'minutes' => ['required', 'file', 'mimes:pdf', 'max:8192'],
        ]);
        $actor = $request->user()->id;

        DB::transaction(function () use ($data, $member, $application, $actor, $evidence, $eligibility): void {
            DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtext(?))', ['board-roles']);
            $reviewer = User::query()->findOrFail($actor);
            $profile = DB::table('members')->lockForUpdate()->find($member);
            abort_if($profile === null, 404);
            $record = DB::table('loan_applications')->where('member_id', $member)->lockForUpdate()->find($application);
            abort_if($record === null, 404);
            abort_if($actor === $record->created_by || ! $this->reviewerMayDecide($reviewer, $member), 403);

            if ($record->status !== 'submitted' || ($data['decision'] === 'approved' && $profile->status !== 'active')) {
                throw ValidationException::withMessages(['decision' => 'This application is no longer eligible for that decision.']);
            }

            if ($data['decision'] === 'approved') {
                if ($record->loan_eligibility_revision_id !== null) {
                    $eligibility->requireEligible($member);
                } elseif (DB::table('member_exits')->where('member_id', $member)->whereIn('status', ['requested', 'approved'])->exists()) {
                    throw ValidationException::withMessages(['eligibility' => 'The member has an open exit request.']);
                }
            }

            DB::table('loan_application_decisions')->insert([
                'loan_application_id' => $application,
                'reviewed_by' => $actor,
                'decision' => $data['decision'],
                'reason' => $data['reason'],
                'reviewed_at' => now(),
                'minutes_id' => $evidence->record($data['minutes']),
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

    public function minutes(int $member, int $application, LoanEvidence $evidence): Response
    {
        $id = DB::table('loan_application_decisions as decision')
            ->join('loan_applications as application', 'application.id', '=', 'decision.loan_application_id')
            ->where('application.id', $application)->where('application.member_id', $member)->value('minutes_id');
        abort_if($id === null, 404);

        return $evidence->download($id, "board-minutes-$application.pdf");
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

    private function member(int $id): stdClass
    {
        $profile = DB::table('members')->find($id, ['id', 'first_name', 'last_name', 'registration_no', 'status']);
        abort_if($profile === null, 404);

        return $profile;
    }
}
