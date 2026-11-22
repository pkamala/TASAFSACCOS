<?php

namespace App\Http\Controllers;

use App\MonthlyMemberStatementImport;
use App\MonthlyStatementDrafts;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MonthlyStatementDraftController extends Controller
{
    public function index(Request $request, MonthlyStatementDrafts $source): View
    {
        [$period, $start, $end] = $this->period($request);
        $drafts = $source->forMonth($start, $end);
        $ready = $drafts->whereNull('hold')->count();

        return view('staff.monthly-statement-drafts', compact('period', 'start', 'end', 'drafts', 'ready'));
    }

    public function download(Request $request, MonthlyStatementDrafts $source): StreamedResponse
    {
        [$period, $start, $end] = $this->period($request);
        $drafts = $source->forMonth($start, $end)->whereNull('hold');
        if ($drafts->isEmpty()) {
            throw ValidationException::withMessages(['period' => 'No statements are ready to export for this month.']);
        }

        return response()->streamDownload(function () use ($drafts, $source): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, MonthlyMemberStatementImport::Headers, escape: '');
            foreach ($drafts as $row) {
                fputcsv($stream, $source->reviewRow($row), escape: '');
            }
            fclose($stream);
        }, "monthly-statement-draft-$period.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function period(Request $request): array
    {
        $today = CarbonImmutable::now('Africa/Dar_es_Salaam');
        $data = $request->validate(['period' => ['nullable', 'regex:/\A[2-9]\d{3}-(?:0[1-9]|1[0-2])\z/']]);
        $period = $data['period'] ?? $today->subMonthNoOverflow()->format('Y-m');
        if ($period >= $today->format('Y-m')) {
            throw ValidationException::withMessages(['period' => 'Choose a closed month.']);
        }
        $month = CarbonImmutable::parse($period.'-01', 'Africa/Dar_es_Salaam');

        return [$period, $month->toDateString(), $month->endOfMonth()->toDateString()];
    }
}
