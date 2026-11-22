<?php

namespace App;

use Illuminate\Support\Facades\DB;
use stdClass;

class SiteBranding
{
    public const Themes = [
        'forest' => 'Forest green',
        'ocean' => 'Ocean blue',
        'plum' => 'Plum purple',
    ];

    public function current(): stdClass
    {
        return DB::table('branding_revisions')->orderByDesc('id')->first(['id', 'name', 'mark', 'theme', 'logo_type'])
            ?? (object) ['id' => null, 'name' => 'TASAF SACCOS', 'mark' => 'T', 'theme' => 'forest', 'logo_type' => null];
    }
}
