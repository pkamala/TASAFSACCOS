<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_branding_revisions_update_the_public_header_and_logo(): void
    {
        $path = '/staff/branding';
        $input = ['name' => 'Example Cooperative', 'mark' => 'ec', 'theme' => 'ocean'];
        $this->get('/')->assertOk()->assertSee('TASAF SACCOS');
        $this->get($path)->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create(['board_role' => 'secretary']))->patch($path, $input)->assertForbidden();
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $this->actingAs($admin)->from($path)->patch($path, [...$input, 'theme' => 'invalid'])->assertSessionHasErrors('theme');
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $this->patch($path, [...$input, 'logo' => $svg])->assertSessionHasErrors('logo');

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZ1sAAAAASUVORK5CYII=');
        $logo = UploadedFile::fake()->createWithContent('logo.png', $png);
        $this->patch($path, [...$input, 'logo' => $logo])->assertRedirect($path);
        $this->get($path)->assertOk()->assertSee('Branding history')->assertSee($admin->name);
        $first = DB::table('branding_revisions')->sole();
        $logoUrl = route('branding.logo', $first->id);
        $this->assertDatabaseHas('branding_revisions', ['changed_by' => $admin->id, 'mark' => 'EC', 'logo_type' => 'image/png']);
        $this->get($logoUrl)->assertOk()->assertHeader('Content-Type', 'image/png')->assertContent($png);
        $this->get('/')->assertOk()->assertSee('Example Cooperative')->assertSee('data-theme="ocean"', false)->assertSee($logoUrl);

        $this->patch($path, [...$input, 'theme' => 'plum'])->assertRedirect($path);
        $this->assertDatabaseHas('branding_revisions', ['theme' => 'plum', 'logo_type' => 'image/png']);
        $this->patch($path, [...$input, 'theme' => 'plum', 'remove_logo' => '1'])->assertRedirect($path);
        $this->get($logoUrl)->assertNotFound();
        $this->get('/')->assertOk()->assertSee('data-theme="plum"', false)->assertSee('>EC<', false)->assertDontSee('rel="icon"', false);
        $this->assertDatabaseCount('branding_revisions', 3);
    }
}
