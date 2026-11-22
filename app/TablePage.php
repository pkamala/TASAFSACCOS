<?php

namespace App;

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
}
