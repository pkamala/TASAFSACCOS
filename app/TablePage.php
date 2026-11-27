<?php

namespace App;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

class TablePage
{
    public const Sizes = [10, 25, 50, 100];

    public static function key(string $page = 'page'): string
    {
        return $page === 'page' ? 'per_page' : $page.'_size';
    }

    public static function size(string $page = 'page'): int
    {
        $value = request()->query()[self::key($page)] ?? null;

        return in_array($value, array_map(strval(...), self::Sizes), true) ? (int) $value : 10;
    }

    public static function searchKey(string $page = 'page'): string
    {
        return $page === 'page' ? 'table_search' : $page.'_search';
    }

    public static function searchTerm(string $page = 'page'): string
    {
        $value = request()->query()[self::searchKey($page)] ?? '';

        return is_string($value) ? trim(mb_substr($value, 0, 100)) : '';
    }

    public static function memberStatus(string $column): Closure
    {
        return static function (Builder|EloquentBuilder $query) use ($column): void {
            $status = request()->query('member_status');

            if (in_array($status, ['active', 'inactive', 'exited', 'deceased'], true)) {
                $query->where($column, $status);
            }
        };
    }

    /** @param list<string> $columns */
    public static function search(array $columns, string $page = 'page'): Closure
    {
        return static function (Builder|EloquentBuilder $builder) use ($columns, $page): void {
            $terms = preg_split('/\s+/u', self::searchTerm($page), flags: PREG_SPLIT_NO_EMPTY);
            if (! $terms) {
                return;
            }

            $query = $builder instanceof EloquentBuilder ? $builder->getQuery() : $builder;
            $fields = implode(', ', array_map($query->getGrammar()->wrap(...), $columns));
            foreach ($terms as $term) {
                $query->whereRaw("concat_ws(' ', {$fields}) ILIKE ?", ['%'.addcslashes($term, '%_\\').'%']);
            }
        };
    }
}
