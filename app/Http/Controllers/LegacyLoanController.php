<?php

namespace App\Http\Controllers;

use App\TablePage;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use stdClass;

class LegacyLoanController extends Controller
{
    public function index(int $member): View
    {
        $profile = $this->profile($member);
        $loans = $this->memberLoans($profile)->orderByDesc('source_id')
            ->tap(TablePage::search(['source_id', 'data->loan_code', 'data->loan_status']))
            ->paginate(TablePage::size());

        return view('staff.legacy-loans', compact('profile', 'loans'));
    }

    public function show(int $member, int $loan): View
    {
        $profile = $this->profile($member);
        $record = $this->memberLoans($profile)->where('source_id', $loan)->first();
        abort_if($record === null, 404);

        $schedule = $this->linkedRows($profile, 'loanschedules', 'loan_id', $loan)
            ->orderByRaw("(data->>'month')::integer NULLS LAST")
            ->orderBy('legacy_onboarding_rows.source_id')
            ->tap(TablePage::search(['legacy_onboarding_rows.source_id', 'data->month', 'data->due_date'], 'schedule_page'))
            ->paginate(TablePage::size('schedule_page'), ['*'], 'schedule_page');
        $repayments = $this->linkedRows($profile, 'repayments', 'resolved_loan_id', $loan)
            ->orderByRaw("data->>'paymentdate' DESC NULLS LAST")
            ->orderByDesc('legacy_onboarding_rows.source_id')
            ->tap(TablePage::search(['legacy_onboarding_rows.source_id', 'data->paymentdate', 'data->payment_id'], 'repayment_page'))
            ->paginate(TablePage::size('repayment_page'), ['*'], 'repayment_page');
        $schedule->appends(['repayment_page' => $repayments->currentPage()]);
        $repayments->appends(['schedule_page' => $schedule->currentPage()]);

        return view('staff.legacy-loan', compact('profile', 'record', 'schedule', 'repayments'));
    }

    private function profile(int $member): stdClass
    {
        $profile = DB::table('members')->find($member, [
            'id', 'first_name', 'last_name', 'registration_no', 'legacy_member_id', 'legacy_source_sha256',
        ]);
        abort_if($profile === null, 404);

        return $profile;
    }

    private function memberLoans(stdClass $profile): Builder
    {
        return $this->source($profile, 'loans')
            ->whereRaw("data->>'member_id' = ?", [(string) $profile->legacy_member_id]);
    }

    private function linkedRows(stdClass $profile, string $table, string $legacyField, int $loan): Builder
    {
        return $this->source($profile, $table)
            ->leftJoin('legacy_loan_corrections as correction', function ($join): void {
                $join->on('correction.source_sha256', '=', 'legacy_onboarding_rows.source_sha256')
                    ->on('correction.source_table', '=', 'legacy_onboarding_rows.source_table')
                    ->on('correction.source_id', '=', 'legacy_onboarding_rows.source_id');
            })
            ->where(function (Builder $query) use ($legacyField, $loan): void {
                $query->where('correction.loan_id', $loan)->orWhere(function (Builder $query) use ($legacyField, $loan): void {
                    $query->whereNull('correction.source_id')
                        ->whereRaw('data->>CAST(? AS text) = ?', [$legacyField, (string) $loan]);
                });
            })
            ->select('legacy_onboarding_rows.*', 'correction.schedule_id as confirmed_schedule_id');
    }

    private function source(stdClass $profile, string $table): Builder
    {
        return DB::table('legacy_onboarding_rows')
            ->where('legacy_onboarding_rows.source_sha256', $profile->legacy_source_sha256)
            ->where('legacy_onboarding_rows.source_table', $table);
    }
}
