<?php

namespace App\Http\Controllers;

use App\SiteBranding;
use App\TablePage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BrandingController extends Controller
{
    public function edit(SiteBranding $branding): View
    {
        $settings = $branding->current();
        $themes = SiteBranding::Themes;
        $history = DB::table('branding_revisions as revision')->join('users as actor', 'actor.id', '=', 'revision.changed_by')
            ->orderByDesc('revision.id')->simplePaginate(TablePage::size(), ['revision.name', 'revision.mark', 'revision.theme', 'revision.logo_type', 'revision.changed_at', 'actor.name as actor']);

        return view('staff.branding', compact('settings', 'themes', 'history'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'mark' => ['required', 'regex:/\A[A-Za-z0-9]{1,3}\z/'],
            'theme' => ['required', Rule::in(array_keys(SiteBranding::Themes))],
            'logo' => ['nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024', 'dimensions:max_width=1600,max_height=1600'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);
        $image = $data['logo'] ?? null;
        $remove = (bool) ($data['remove_logo'] ?? false);
        if ($image && $remove) {
            throw ValidationException::withMessages(['logo' => 'Upload a replacement logo or select removal.']);
        }
        $newLogo = $image ? ['logo_type' => $image->getMimeType(), 'logo_base64' => base64_encode($image->get())] : null;

        DB::transaction(function () use ($data, $remove, $newLogo, $request): void {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['branding-revisions']);
            $current = DB::table('branding_revisions')->orderByDesc('id')->first(['logo_base64', 'logo_type']);
            $logo = $newLogo ?? [
                'logo_type' => $remove ? null : $current?->logo_type,
                'logo_base64' => $remove ? null : $current?->logo_base64,
            ];

            DB::table('branding_revisions')->insert([
                'name' => $data['name'], 'mark' => strtoupper($data['mark']), 'theme' => $data['theme'],
                ...$logo, 'changed_by' => $request->user()->id, 'changed_at' => now(),
            ]);
        }, 3);

        return redirect()->route('staff.branding.edit')->with('status', 'Branding saved.');
    }

    public function logo(Request $request, int $revision, SiteBranding $branding): Response
    {
        $current = $branding->current();
        abort_unless($current->id === $revision && $current->logo_type, 404);
        $image = DB::table('branding_revisions')->where('id', $revision)->value('logo_base64');
        $response = response(base64_decode($image, true), 200, [
            'Content-Type' => $current->logo_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, no-cache',
        ])->setEtag('branding-'.$revision);
        $response->isNotModified($request);

        return $response;
    }
}
