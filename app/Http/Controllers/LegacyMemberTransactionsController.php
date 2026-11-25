<?php

namespace App\Http\Controllers;

use App\TablePage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LegacyMemberTransactionsController extends Controller
{
    private const Sections = [
        'savings' => ['membersavingaccounts', 'Savings movements'],
        'shares' => ['membershareaccounts', 'Share movements'],
        'contributions' => ['monthpaymentlists', 'Monthly contribution records'],
    ];

    public function __invoke(Request $request, int $member): View
    {
        $profile = DB::table('members')->find($member, [
            'id', 'first_name', 'last_name', 'registration_no', 'legacy_member_id', 'legacy_source_sha256',
        ]);
        abort_if($profile === null || $profile->legacy_member_id === null, 404);

        $filters = $request->validate(['section' => ['nullable', Rule::in(array_keys(self::Sections))]]);
        $section = $filters['section'] ?? 'savings';
        [$source, $label] = self::Sections[$section];

        $rows = DB::table('legacy_onboarding_rows')->where('source_sha256', $profile->legacy_source_sha256)
            ->where('source_table', $source)
            ->whereRaw("data->>'member_id' = ?", [(string) $profile->legacy_member_id]);

        if ($section === 'contributions') {
            $rows->orderByRaw("data->>'year' DESC NULLS LAST, lpad(data->>'month', 2, '0') DESC NULLS LAST");
        } else {
            $rows->orderByRaw("data->>'date' DESC NULLS LAST");
        }

        $transactions = $rows->orderByDesc('source_id')
            ->tap(TablePage::search(['source_id', 'data->date', 'data->description', 'data->payment_id', 'data->monthly_id', 'data->year', 'data->month', 'data->status']))
            ->paginate(TablePage::size())->appends(['section' => $section])
            ->through(fn ($row): array => [
                'id' => $row->source_id,
                'data' => json_decode($row->data, true, flags: JSON_THROW_ON_ERROR),
                'issues' => json_decode($row->issues, true, flags: JSON_THROW_ON_ERROR),
            ]);

        return view('staff.legacy-member-transactions', [
            'sections' => self::Sections,
            ...compact('profile', 'section', 'label', 'transactions'),
        ]);
    }
}
