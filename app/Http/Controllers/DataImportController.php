<?php

namespace App\Http\Controllers;

use App\ImportTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataImportController extends Controller
{
    private const Headers = [
        'members' => ['registration_no', 'first_name', 'middle_name', 'last_name', 'email', 'phone', 'joining_date', 'status'],
        'chart_accounts' => ['account_code', 'account_name', 'category_name', 'type_name', 'is_ledger_account'],
    ];

    public function index(Request $request): Response
    {
        $preview = $this->previewFor($request);

        return response()->view('staff.data-imports', compact('preview'), headers: ['Cache-Control' => 'private, no-store']);
    }

    public function template(string $kind): StreamedResponse
    {
        abort_unless(isset(self::Headers[$kind]), 404);

        return response()->streamDownload(function () use ($kind): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, self::Headers[$kind], escape: '');
            fclose($stream);
        }, str_replace('_', '-', $kind).'-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function preview(Request $request): RedirectResponse
    {
        $request->session()->forget('initial_data_import');
        $data = $request->validate(['kind' => ['required', 'in:members,chart_accounts'], 'sheet' => ['required', 'file', 'max:2048']]);
        $kind = $data['kind'];
        if (DB::table($kind)->exists()) {
            $this->invalid('This first-load import requires an empty destination.');
        }
        $file = ImportTable::open($data['sheet'], 'sheet');
        $file->setCsvControl(',', '"', '');
        $header = $file->fgetcsv() ?: [];
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0] ?? '');
        if ($header !== self::Headers[$kind]) {
            $this->invalid('Use the column headers from the selected import template.');
        }

        $rows = [];
        $keys = [];
        $line = 1;
        while (! $file->eof()) {
            $values = $file->fgetcsv();
            $line++;
            if ($values === false || $values === [null]) {
                continue;
            }
            if (count($values) !== count($header) || count($rows) >= 1000) {
                $this->invalid("Invalid columns at row $line or more than 1,000 records.");
            }
            $row = array_combine($header, array_map('trim', $values));
            $rows[] = $kind === 'members' ? $this->member($row, $keys, $line) : $this->account($row, $keys, $line);
        }
        if ($rows === []) {
            $this->invalid('The file contains no records.');
        }

        $request->session()->put('initial_data_import', [
            'kind' => $kind, 'rows' => $rows, 'actor' => $request->user()->id,
            'token' => Str::random(40), 'sha256' => hash_file('sha256', $data['sheet']->getPathname()),
        ]);

        return redirect()->route('staff.data-imports.index')->withFragment('initial-import-preview');
    }

    public function apply(Request $request): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:40']]);
        $preview = $this->previewFor($request);
        if ($preview === null || ! hash_equals($preview['token'], $data['token'])) {
            $this->invalid('Preview the file before applying the import.');
        }

        DB::transaction(function () use ($preview): void {
            $kind = $preview['kind'];
            if ($kind === 'members') {
                DB::statement('LOCK TABLE members IN SHARE ROW EXCLUSIVE MODE');
            } else {
                DB::statement('LOCK TABLE account_categories, account_types, chart_accounts IN SHARE ROW EXCLUSIVE MODE');
            }
            if (DB::table($kind)->exists()) {
                $this->invalid('The destination already has records. The first-load import was not applied.');
            }

            if ($kind === 'members') {
                $this->loadMembers($preview);
            } else {
                $this->loadChart($preview);
            }
        }, 3);

        $request->session()->forget('initial_data_import');

        return redirect()->route('staff.data-imports.index')->with('status', count($preview['rows']).' records imported.');
    }

    private function member(array $row, array &$keys, int $line): array
    {
        $row['registration_no'] = Str::upper($row['registration_no']);
        $row['email'] = Str::lower($row['email']);
        $row['middle_name'] = $row['middle_name'] === '' ? null : $row['middle_name'];
        $row['status'] = Str::lower($row['status']);
        $validator = Validator::make($row, [
            'registration_no' => ['required', 'regex:/\A[A-Z0-9-]{1,30}\z/'],
            'first_name' => ['required', 'max:100'],
            'middle_name' => ['nullable', 'max:100'],
            'last_name' => ['required', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'regex:/\A\+?[0-9]{10,15}\z/'],
            'joining_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.now('Africa/Dar_es_Salaam')->toDateString()],
            'status' => ['required', 'in:active,inactive'],
        ]);
        $this->validateRow($validator, $row, $keys, ['registration_no', 'email'], $line);

        return $row;
    }

    private function account(array $row, array &$keys, int $line): array
    {
        $validator = Validator::make($row, [
            'account_code' => ['required', 'regex:/\A[\x20-\x7e]{1,100}\z/'],
            'account_name' => ['required', 'max:255'],
            'category_name' => ['required', 'max:255'],
            'type_name' => ['required', 'max:255'],
            'is_ledger_account' => ['required', 'in:0,1'],
        ]);
        $this->validateRow($validator, $row, $keys, ['account_code'], $line);
        $row['is_ledger_account'] = $row['is_ledger_account'] === '1';

        return $row;
    }

    private function validateRow(\Illuminate\Contracts\Validation\Validator $validator, array $row, array &$keys, array $unique, int $line): void
    {
        $text = implode('', $row);
        if (preg_match('//u', $text) !== 1 || str_contains($text, "\0")) {
            $this->invalid("Use valid UTF-8 text in row $line.");
        }
        if ($validator->fails()) {
            $this->invalid("Invalid fields in row $line: ".implode(', ', $validator->errors()->keys()).'.');
        }
        foreach ($unique as $column) {
            $key = Str::lower($row[$column]);
            if (isset($keys[$column][$key])) {
                $this->invalid("Repeated $column in row $line.");
            }
            $keys[$column][$key] = true;
        }
    }

    private function loadMembers(array $preview): void
    {
        $now = now();
        foreach ($preview['rows'] as $row) {
            $id = DB::table('members')->insertGetId([...$row, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('member_audits')->insert([
                'member_id' => $id, 'actor_id' => $preview['actor'], 'recorded_at' => $now,
                'changes' => json_encode(['membership' => ['before' => null, 'after' => 'Initial register import '.$preview['sha256']]], JSON_THROW_ON_ERROR),
            ]);
        }
    }

    private function loadChart(array $preview): void
    {
        $now = now();
        $categories = DB::table('account_categories')->get()->keyBy(fn ($row) => Str::lower($row->name))->map->id->all();
        $types = [];
        foreach (DB::table('account_types')->get() as $type) {
            $types[$type->account_category_id][Str::lower($type->name)] = $type->id;
        }
        foreach ($preview['rows'] as $row) {
            $category = $categories[Str::lower($row['category_name'])] ??= DB::table('account_categories')
                ->insertGetId(['name' => $row['category_name']]);
            $type = $types[$category][Str::lower($row['type_name'])] ??= DB::table('account_types')
                ->insertGetId(['name' => $row['type_name'], 'account_category_id' => $category]);
            DB::table('chart_accounts')->insert([
                'account_type_id' => $type, 'code' => $row['account_code'], 'name' => $row['account_name'],
                'is_ledger_account' => $row['is_ledger_account'], 'created_by' => $preview['actor'],
                'created_at' => $now, 'creation_reason' => 'Initial chart import '.$preview['sha256'],
            ]);
        }
    }

    private function previewFor(Request $request): ?array
    {
        $preview = $request->session()->get('initial_data_import');

        return ($preview['actor'] ?? null) === $request->user()->id ? $preview : null;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['sheet' => $message]);
    }
}
