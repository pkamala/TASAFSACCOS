<?php

namespace App;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

class LoanApproval
{
    public const PendingLanes = ['pending', 'pending_board'];

    public const Roles = ['staff' => 'Loan intake staff', 'committee' => 'Loan committee member', 'board' => 'Board member'];

    public function borrowerLane(int $member): string
    {
        return $this->boardBorrower(DB::table('users')->where('member_id', $member))->exists() ? 'board' : 'committee';
    }

    public function boardBorrower(Builder $query): Builder
    {
        return $query->where('is_staff_admin', false)->where(fn (Builder $role) => $role->whereJsonContains('loan_workflow_roles', 'committee')
            ->orWhereJsonContains('loan_workflow_roles', 'board')
            ->orWhereIn('board_role', ['chairperson', 'secretary']));
    }

    public function forReviewer(Builder $query, User $actor, string $alias): Builder
    {
        $boardOfficer = $actor->isBoardOfficer();
        $committeeMember = $actor->hasLoanRole('committee');
        if (! $boardOfficer && ! $committeeMember) {
            return $query->whereRaw('false');
        }

        return $query->where("$alias.created_by", '<>', $actor->id)
            ->when($actor->member_id !== null, fn (Builder $loans) => $loans->where("$alias.member_id", '<>', $actor->member_id))
            ->where(function (Builder $loans) use ($actor, $alias, $boardOfficer, $committeeMember): void {
                $borrower = fn (Builder $user): Builder => $this->boardBorrower(
                    $user->selectRaw('1')->from('users as borrower')->whereColumn('borrower.member_id', "$alias.member_id")
                );

                if ($boardOfficer) {
                    $loans->where(function (Builder $board) use ($alias, $borrower): void {
                        $board->whereNull("$alias.approval_lane")->orWhere("$alias.approval_lane", 'board')
                            ->orWhere(fn (Builder $promoted) => $promoted->where("$alias.approval_lane", 'committee')->whereExists($borrower));
                    })->whereNotExists(fn (Builder $user) => $user->selectRaw('1')->from('users as borrower')
                        ->whereColumn('borrower.member_id', "$alias.member_id")->where('borrower.board_role', $actor->board_role));
                }

                if ($committeeMember) {
                    $loans->orWhere(fn (Builder $committee) => $committee->where("$alias.approval_lane", 'committee')->whereNotExists($borrower));
                }
            });
    }

    public function mayReview(User $actor, stdClass $loan): bool
    {
        return $this->forReviewer(DB::table('loan_applications as loan')->where('loan.id', $loan->id), $actor, 'loan')->exists();
    }

    public function mayView(User $actor, stdClass $loan): bool
    {
        return $actor->is_staff_admin || $actor->isBoardOfficer()
            || ($actor->hasLoanRole('staff') && $actor->id === $loan->created_by)
            || $this->mayReview($actor, $loan);
    }
}
