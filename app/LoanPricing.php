<?php

namespace App;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

class LoanPricing
{
    public function categories(): Collection
    {
        return DB::table('loan_categories')->orderBy('id')->get(['id', 'name']);
    }

    public function current(int $category): ?stdClass
    {
        return DB::table('loan_pricing_revisions')
            ->where(fn ($query) => $query->where('loan_category_id', $category)->orWhereNull('loan_category_id'))
            ->orderByRaw('loan_category_id IS NULL')->orderByDesc('id')->first();
    }

    public function lock(int $category, bool $exclusive = false): void
    {
        $query = $exclusive ? 'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))'
            : 'SELECT pg_advisory_xact_lock_shared(hashtextextended(?, 0))';
        DB::selectOne($query, ['loan-pricing:'.$category]);
    }
}
