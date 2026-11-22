<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

class DataImportsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_preview_and_apply_excel_member_and_chart_data_then_post_the_opening_journal_by_code(): void
    {
        $this->travelTo(now('Africa/Dar_es_Salaam')->setDate(2026, 11, 22)->setTime(12, 0));
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $path = '/staff/data-imports';
        $this->get($path)->assertRedirect('/staff/login');
        $this->actingAs(User::factory()->create(['board_role' => 'secretary']))->post("$path/preview")->assertForbidden();
        $this->actingAs($admin)->get($path)->assertOk()->assertSee('Data imports');
        $this->get('/staff/loan-reconciliation')->assertNotFound();
        $this->get('/staff/loan-corrections')->assertNotFound();
        $members = [
            ['registration_no', 'first_name', 'middle_name', 'last_name', 'email', 'phone', 'joining_date', 'status'],
            ['0081', 'Asha', '', 'Example', 'asha@example.com', '+255712345678', 45000, 'active'],
        ];
        $this->post("$path/preview", ['kind' => 'members', 'sheet' => $this->workbook($members)])->assertRedirect("$path#initial-import-preview");
        $this->get($path)->assertOk()->assertSee('0081')->assertSee('1 valid records');
        $this->assertDatabaseEmpty('members');
        $this->post("$path/apply", ['token' => session('initial_data_import.token')])->assertRedirect($path);
        $this->assertDatabaseHas('members', ['registration_no' => '0081', 'email' => 'asha@example.com', 'joining_date' => '2023-03-15']);
        $this->assertDatabaseHas('member_audits', ['actor_id' => $admin->id]);

        $chart = [
            ['account_code', 'account_name', 'category_name', 'type_name', 'is_ledger_account'],
            ['001', 'Bank', 'Asset', 'Cash', 1],
            ['002', 'Member savings', 'Liability', 'Savings', 1],
        ];
        $this->post("$path/preview", ['kind' => 'chart_accounts', 'sheet' => $this->workbook($chart)])->assertRedirect();
        $this->post("$path/apply", ['token' => session('initial_data_import.token')])->assertRedirect($path);
        $this->assertDatabaseHas('chart_accounts', ['code' => '001', 'legacy_id' => null, 'created_by' => $admin->id]);

        $opening = [['account_code', 'debit_tzs', 'credit_tzs'], ['001', 1000, 0], ['002', 0, 1000]];
        $this->post('/staff/ledger-opening/preview', [
            'effective_on' => '2026-11-22', 'review_reference' => 'Approved opening', 'opening_journal' => $this->workbook($opening),
        ])->assertRedirect('/staff/ledger-opening#opening-lines');
        $this->post('/staff/ledger-opening/post', ['token' => session('ledger_opening_preview.token')])->assertRedirect('/staff/ledger-opening');
        DB::statement('SET CONSTRAINTS ledger_opening_balanced IMMEDIATE');
        $this->assertDatabaseCount('ledger_opening_lines', 2);
        $this->get('/staff/ledger-financial-reports')->assertOk()
            ->assertViewHas('totals', fn (array $totals): bool => $totals['difference'] === 0 && $totals['assets'] === 100000);
    }

    public function test_workbooks_cannot_import_formulas_and_demo_mode_disables_outgoing_delivery(): void
    {
        $admin = User::factory()->create(['is_staff_admin' => true]);
        $this->actingAs($admin)->from('/staff/data-imports')->post('/staff/data-imports/preview', [
            'kind' => 'members', 'sheet' => $this->workbook([['registration_no']], formula: true),
        ])->assertSessionHasErrors(['sheet' => 'Replace formulas and error cells with reviewed values before importing.']);
        $this->assertDatabaseEmpty('members');

        config(['app.demo_mode' => true, 'mail.default' => 'smtp']);
        $this->get('/staff/data-imports')->assertOk()->assertSee('Demo environment');
        $this->artisan('statements:send', ['--send' => true])->assertFailed();
    }

    private function workbook(array $rows, bool $formula = false): UploadedFile
    {
        $file = tempnam(sys_get_temp_dir(), 'test-workbook-');
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets><sheet name="Data" sheetId="1"/></sheets></workbook>');
        $sheet = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $index => $row) {
            $sheet .= '<row>';
            foreach ($row as $column => $value) {
                $cell = chr(65 + $column).($index + 1);
                $sheet .= match (true) {
                    $formula => "<c r=\"$cell\"><f>1+1</f><v>2</v></c>",
                    is_int($value) => "<c r=\"$cell\"><v>$value</v></c>",
                    default => "<c r=\"$cell\" t=\"inlineStr\"><is><t>".htmlspecialchars($value, ENT_XML1).'</t></is></c>',
                };
            }
            $sheet .= '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet.'</sheetData></worksheet>');
        $zip->close();
        $workbook = UploadedFile::fake()->createWithContent('data.xlsx', file_get_contents($file));
        unlink($file);

        return $workbook;
    }
}
