@extends('layout')
@section('title', 'Ledger opening journal')
@section('content')
    <div class="page-intro">
        <a class="print-hide" href="{{ route('staff.loan-ledger.index') }}">SACCOS ledger trial balance</a>
        <p class="print-only">TASAF SACCOS</p>
        <h1>Ledger opening journal</h1>
        @if ($posted)
            <p>Cutover {{ $posted->effective_on }} · Posted by {{ $posted->actor }} on {{ \Carbon\CarbonImmutable::parse($posted->posted_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT</p>
            <p>Finance reference {{ $posted->review_reference }}</p>
            <details><summary>Source checksum</summary><code>{{ $posted->source_sha256 }}</code></details>
            <p><button class="primary print-hide" type="button" onclick="window.print()">Print opening journal</button></p>
        @else
            <p>Import the finance-approved trial balance at the start of the cutover date. Earlier activity must already be included in that trial balance.</p>
        @endif
    </div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @include('form-errors')

    @unless ($posted)
        <section class="panel admin-profile">
            <h2>Prepare the opening journal</h2>
            <p><a href="{{ route('staff.ledger-opening.template') }}">Download the opening journal CSV template</a></p>
            <p>Use one row per account with a nonzero balance. Enter its legacy ID and account code, with a positive TZS amount on either the debit or credit side.</p>
            <form action="{{ route('staff.ledger-opening.preview') }}" method="POST" enctype="multipart/form-data" class="fields">
                @csrf
                <label for="effective_on">Cutover date</label>
                <input id="effective_on" name="effective_on" type="date" value="{{ is_string(old('effective_on')) ? old('effective_on') : ($preview['effective_on'] ?? '') }}" required>
                <label for="review_reference">Approved trial balance or sign-off reference</label>
                <textarea id="review_reference" name="review_reference" maxlength="500" required>{{ is_string(old('review_reference')) ? old('review_reference') : ($preview['review_reference'] ?? '') }}</textarea>
                <label for="opening_journal">Opening balances (CSV)</label>
                <input id="opening_journal" name="opening_journal" type="file" accept=".csv,text/csv" required>
                <button class="primary">Preview opening journal</button>
            </form>
        </section>
    @endunless

    @if ($lines->isNotEmpty())
        <section class="panel stacked-panel print-register" id="opening-lines">
            <h2>{{ $posted ? 'Posted opening balances' : 'Preview · '.$preview['effective_on'] }}</h2>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Opening journal lines">
                <table>
                    <thead><tr><th scope="col">Legacy account ID</th><th scope="col">Account code</th><th scope="col">Account</th><th scope="col" class="money">Debit (TZS)</th><th scope="col" class="money">Credit (TZS)</th>@unless($posted)<th scope="col">Result</th>@endunless</tr></thead>
                    <tbody>
                        @foreach ($lines as $row)
                            <tr>
                                <th scope="row">{{ $row['legacy_id'] }}</th><td>{{ $row['code'] }}</td><td>{{ $row['name'] ?? '—' }}</td>
                                <td class="money">{{ number_format(max(0, $row['signed_minor']) / 100, 2) }}</td>
                                <td class="money">{{ number_format(max(0, -$row['signed_minor']) / 100, 2) }}</td>
                                @unless($posted)<td>{{ $row['status'] }}</td>@endunless
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr><th scope="row" colspan="3">Totals</th><td class="money">{{ number_format($lines->where('signed_minor', '>', 0)->sum('signed_minor') / 100, 2) }}</td><td class="money">{{ number_format(-$lines->where('signed_minor', '<', 0)->sum('signed_minor') / 100, 2) }}</td>@unless($posted)<td></td>@endunless</tr></tfoot>
                </table>
            </div>
            @if ($preview && !$posted)
                @if ($exceptions)
                    <p>Resolve the account exceptions before posting.</p>
                @else
                    <form method="POST" action="{{ route('staff.ledger-opening.post') }}" class="stacked-panel print-hide">
                        @csrf
                        <input type="hidden" name="token" value="{{ $preview['token'] }}">
                        <button class="primary">Post opening journal</button>
                    </form>
                @endif
            @endif
        </section>
    @endif
@endsection
