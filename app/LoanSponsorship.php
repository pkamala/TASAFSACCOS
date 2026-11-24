<?php

namespace App;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

class LoanSponsorship
{
    public const RequiredCount = 2;

    public function mayChange(stdClass $loan): bool
    {
        return $loan->status === 'submitted' && in_array($loan->approval_lane, [null, ...LoanApproval::PendingLanes], true);
    }

    public function forApplication(int $application, bool $lock = false): Collection
    {
        return DB::table('loan_sponsorships as sponsorship')->where('sponsorship.loan_application_id', $application)
            ->join('members as sponsor', 'sponsor.id', '=', 'sponsorship.member_id')
            ->when($lock, fn (Builder $query) => $query->sharedLock())
            ->orderBy('sponsorship.id')->get([
                'sponsor.registration_no', 'sponsor.first_name', 'sponsor.last_name', 'sponsor.status', 'sponsorship.confirmed_at',
            ]);
    }

    public function confirmedActiveCount(string $alias): Builder
    {
        return DB::table('loan_sponsorships as sponsorship')->selectRaw('count(*)')
            ->join('members as sponsor', 'sponsor.id', '=', 'sponsorship.member_id')
            ->whereColumn('sponsorship.loan_application_id', "$alias.id")
            ->where('sponsor.status', 'active')->whereNotNull('sponsorship.confirmed_at');
    }

    public function ready(Collection $sponsors): bool
    {
        return $sponsors->count() === self::RequiredCount
            && $sponsors->every(fn (stdClass $sponsor): bool => $sponsor->status === 'active' && $sponsor->confirmed_at !== null);
    }

    public function nominate(int $application, int $borrower, array $registrationNumbers): void
    {
        $numbers = array_map(fn (string $number): string => Str::upper(trim($number)), $registrationNumbers);
        $members = $this->eligibleMembers($borrower)->whereIn('sponsor.registration_no', $numbers)
            ->orderBy('sponsor.id')->sharedLock()->pluck('sponsor.id')->all();

        if (count($members) !== self::RequiredCount) {
            throw ValidationException::withMessages([
                'sponsors' => 'Enter two different active members with member accounts. The borrower cannot be a sponsor.',
            ]);
        }

        $nominations = DB::table('loan_sponsorships')->where('loan_application_id', $application);
        (clone $nominations)->whereNotIn('member_id', $members)->delete();
        $retained = $nominations->pluck('member_id')->all();
        $new = array_diff($members, $retained);
        if ($new !== []) {
            DB::table('loan_sponsorships')->insert(array_map(fn (int $member): array => [
                'loan_application_id' => $application,
                'member_id' => $member,
            ], array_values($new)));
        }
    }

    public function search(int $borrower, string $search): Collection
    {
        return $this->eligibleMembers($borrower)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn (Builder $matches) => $matches->whereLike('sponsor.registration_no', $term)
                    ->orWhereRaw("concat_ws(' ', sponsor.first_name, sponsor.middle_name, sponsor.last_name) ILIKE ?", [$term])
                    ->orWhereRaw("concat_ws(' ', sponsor.first_name, sponsor.last_name) ILIKE ?", [$term]));
            })
            ->orderByRaw('lower(sponsor.first_name), lower(sponsor.last_name)')->orderBy('sponsor.id')
            ->limit(20)->get(['sponsor.first_name', 'sponsor.middle_name', 'sponsor.last_name', 'sponsor.registration_no']);
    }

    private function eligibleMembers(int $borrower): Builder
    {
        return DB::table('members as sponsor')->where('sponsor.id', '<>', $borrower)->where('sponsor.status', 'active')
            ->whereExists(fn (Builder $users) => $users->selectRaw('1')->from('users')
                ->whereColumn('users.member_id', 'sponsor.id')->where('users.is_staff_admin', false));
    }

    public function rules(): array
    {
        return [
            'sponsors' => ['required', 'array', 'list', 'size:'.self::RequiredCount],
            'sponsors.*' => ['required', 'string', 'max:30'],
        ];
    }
}
