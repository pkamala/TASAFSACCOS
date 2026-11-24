<?php

namespace App\Http\Controllers;

use App\LoanSponsorship;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanSponsorshipController extends Controller
{
    public function search(Request $request, int $member, LoanSponsorship $sponsorship): JsonResponse
    {
        abort_unless(DB::table('members')->where('id', $member)->exists(), 404);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return response()->json($sponsorship->search($member, $data['q'] ?? ''))
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, int $member, int $application, LoanSponsorship $sponsorship): RedirectResponse
    {
        $data = $request->validate($sponsorship->rules());

        DB::transaction(function () use ($member, $application, $data, $sponsorship): void {
            $loan = DB::table('loan_applications')->where('member_id', $member)->lockForUpdate()->find($application);
            abort_if($loan === null, 404);
            if (! $sponsorship->mayChange($loan)) {
                throw ValidationException::withMessages(['sponsors' => 'Sponsors can be changed before the application is forwarded.']);
            }

            $sponsorship->nominate($application, $member, $data['sponsors']);
        }, 3);

        return redirect()->route('staff.loan-applications.show', [$member, $application])->with('status', 'Loan sponsors saved.');
    }

    public function confirm(Request $request, int $nomination, LoanSponsorship $sponsorship): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor->member_id === null || $actor->is_staff_admin, 403);

        DB::transaction(function () use ($actor, $nomination, $sponsorship): void {
            $query = DB::table('loan_sponsorships')->where('id', $nomination)->where('member_id', $actor->member_id);
            $application = (clone $query)->value('loan_application_id');
            abort_if($application === null, 404);
            $loan = DB::table('loan_applications')->lockForUpdate()->find($application);
            $record = $query->first();
            abort_if($record === null || $loan === null || $loan->member_id === $actor->member_id, 404);

            if ($record->confirmed_at !== null) {
                return;
            }

            $member = DB::table('members')->sharedLock()->find($actor->member_id);
            abort_unless($member?->status === 'active', 403);
            if (! $sponsorship->mayChange($loan)) {
                throw ValidationException::withMessages(['sponsorship' => 'This loan is no longer accepting sponsor confirmations.']);
            }

            $query->update(['confirmed_by' => $actor->id, 'confirmed_at' => now()]);
        }, 3);

        return redirect()->route('member.account')->with('status', 'Sponsorship confirmed.');
    }
}
