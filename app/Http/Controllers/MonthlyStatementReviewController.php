<?php

namespace App\Http\Controllers;

use App\Mail\MonthlyMemberStatementMail;
use App\MonthlyMemberStatementImport;
use App\MonthlyStatementDrafts;
use App\StatementMailer;
use App\TablePage;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class MonthlyStatementReviewController extends Controller
{
    public function index(Request $request, MonthlyMemberStatementImport $imports, StatementMailer $mailer): View
    {
        [$selected, $period] = $this->selectedPeriod($request, $imports);
        $preview = $this->previewFor($request);
        if (($preview['period'] ?? null) !== $period) {
            $preview = null;
        }
        $rows = DB::table('monthly_member_statements as statement')
            ->join('members as member', 'member.id', '=', 'statement.member_id')->where('statement.period', $period);
        $mismatch = 'statement.delivery_status = ? AND lower(member.email COLLATE "C") IS DISTINCT FROM statement.recipient';
        $counts = (clone $rows)->selectRaw(
            "CASE WHEN $mismatch THEN 'recipient_mismatch' ELSE statement.delivery_status END AS status, count(*) AS total",
            ['pending'],
        )->groupByRaw('1')->pluck('total', 'status');
        $queued = $rows->leftJoin('users as actor', 'actor.id', '=', 'statement.queued_by')
            ->select('statement.*', 'member.registration_no', 'member.first_name', 'member.last_name', 'actor.name as reviewer')
            ->selectRaw("($mismatch) AS recipient_mismatch", ['pending'])
            ->orderByRaw('lower(member.first_name), lower(member.last_name)')->orderBy('statement.id')
            ->tap(TablePage::search(['member.registration_no', 'member.first_name', 'member.last_name', 'statement.recipient', 'statement.delivery_status', 'actor.name']))
            ->paginate(TablePage::size())->appends(['period' => $selected]);
        $deliveryConfigured = $mailer->configured($mailer->selectedAccountId());

        $automatic = $mailer->automaticEnabled();

        return view('staff.monthly-statement-review', compact('selected', 'preview', 'queued', 'counts', 'deliveryConfigured', 'automatic'));
    }

    public function sample(Request $request, MonthlyMemberStatementImport $imports, Markdown $markdown): View
    {
        [$selected, $period] = $this->selectedPeriod($request, $imports);
        $date = CarbonImmutable::parse($period, 'Africa/Dar_es_Salaam');
        $mail = new MonthlyMemberStatementMail($date->format('F Y'), $date->endOfMonth()->format('d F Y'), 124500050, 5000000, 47500000);
        $email = $markdown->render($mail->content()->markdown, $mail->buildViewData());
        $subject = $mail->envelope()->subject;

        return view('staff.monthly-statement-sample', compact('selected', 'email', 'subject'));
    }

    public function preview(Request $request, MonthlyMemberStatementImport $imports): RedirectResponse
    {
        return $this->review($request, $imports);
    }

    public function previewDrafts(Request $request, MonthlyMemberStatementImport $imports, MonthlyStatementDrafts $drafts): RedirectResponse
    {
        return $this->review($request, $imports, $drafts);
    }

    private function review(Request $request, MonthlyMemberStatementImport $imports, ?MonthlyStatementDrafts $drafts = null): RedirectResponse
    {
        $request->session()->forget('monthly_statement_review');
        $rules = [
            'period' => ['required', 'string', 'size:7'],
            'review_reference' => ['required', 'string', 'max:500'],
        ];
        if ($drafts === null) {
            $rules['statements'] = ['required', 'file', 'max:1024'];
        }
        $data = $request->validate($rules);
        $field = $drafts === null ? 'statements' : 'period';

        try {
            $period = $imports->period($data['period']);
            $source = $drafts !== null ? $drafts->source($period) : $imports->readCsv($data['statements']->getPathname());
            if ($source[1] === [] || count($source[1]) > 1000) {
                throw new InvalidArgumentException('A review needs between 1 and 1,000 eligible statements.');
            }
            $plan = $imports->plan($period, $source);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }

        $request->session()->put('monthly_statement_review', [
            'actor' => $request->user()->id, 'token' => Str::random(40), 'period' => $period,
            'source' => $source, 'plan' => $plan, 'review_reference' => $data['review_reference'],
            'already_recorded' => count($source[1]) - count($plan), 'system_drafts' => $drafts !== null,
        ]);

        return redirect()->route('staff.statement-review.index', ['period' => $data['period']])->withFragment('preview');
    }

    public function queue(Request $request, MonthlyMemberStatementImport $imports, MonthlyStatementDrafts $drafts): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:40']]);
        $preview = $this->previewFor($request);
        if (! $preview || ! hash_equals($preview['token'], $data['token'])) {
            throw ValidationException::withMessages(['statements' => 'Run a fresh preview before queuing statements.']);
        }

        try {
            $count = DB::transaction(function () use ($imports, $preview, $drafts): int {
                $plan = $imports->plan($preview['period'], $preview['source'], lock: true);
                if ($plan !== $preview['plan'] || (($preview['system_drafts'] ?? false) && $drafts->source($preview['period'])[0] !== $preview['source'][0])) {
                    throw new InvalidArgumentException('The statements or member details changed after preview. Preview the file again.');
                }

                return $imports->queue($plan, $preview['actor'], $preview['review_reference']);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['statements' => 'Statements changed during queuing. Run a fresh preview.']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['statements' => $exception->getMessage()]);
        }

        $request->session()->forget('monthly_statement_review');

        return redirect()->route('staff.statement-review.index', ['period' => substr($preview['period'], 0, 7)])
            ->with('status', "$count statements queued for delivery.");
    }

    private function selectedPeriod(Request $request, MonthlyMemberStatementImport $imports): array
    {
        $selected = $request->validate(['period' => ['nullable', 'string', 'size:7']])['period']
            ?? now('Africa/Dar_es_Salaam')->subMonthNoOverflow()->format('Y-m');
        try {
            return [$selected, $imports->period($selected)];
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['period' => $exception->getMessage()]);
        }
    }

    private function previewFor(Request $request): ?array
    {
        $preview = $request->session()->get('monthly_statement_review');

        return ($preview['actor'] ?? null) === $request->user()->id ? $preview : null;
    }
}
