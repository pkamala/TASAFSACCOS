@extends('layout')
@section('title', 'Legacy savings and contributions')
@section('content')
    @php($money = fn ($amount) => is_numeric($amount) ? number_format((float) $amount, 2) : '—')
    <div class="page-intro">
        <a href="{{ route('staff.members.show', $profile->id) }}">Member profile</a>
        <h1>Legacy savings and contributions</h1>
        <p>{{ $profile->first_name }} {{ $profile->last_name }} · {{ $profile->registration_no }}</p>
        <p class="note">Amounts and statuses come from the legacy export. Monthly contribution records can overlap with savings and share movements. Review the source references before reconciling balances.</p>
    </div>
    <section class="panel" aria-labelledby="transactions">
        <nav class="pagination" aria-label="Transaction types">
            @foreach ($sections as $key => [$table, $caption])
                <a href="{{ route('staff.legacy-member-transactions', ['member' => $profile->id, 'section' => $key]) }}" @if ($key === $section) aria-current="page" @endif>{{ $caption }}</a>
            @endforeach
        </nav>
        <h2 class="stacked-panel" id="transactions">{{ $label }}</h2>
        <div class="table-wrap" tabindex="0" role="region" aria-label="{{ $label }}">
            <table>
                <thead>
                    @if ($section === 'contributions')
                        <tr><th scope="col">Legacy ID</th><th scope="col">Contribution month</th><th scope="col">Payment ID</th><th scope="col" class="money">Savings (TZS)</th><th scope="col" class="money">Shares (TZS)</th><th scope="col">Status in export</th><th scope="col">Review flags</th></tr>
                    @else
                        <tr><th scope="col">Legacy ID</th><th scope="col">Date</th><th scope="col">Description</th><th scope="col">Payment ID</th><th scope="col">Monthly record ID</th><th scope="col" class="money">Debit (TZS)</th><th scope="col" class="money">Credit (TZS)</th>@if ($section === 'shares')<th scope="col">Shares</th>@endif<th scope="col">Review flags</th></tr>
                    @endif
                </thead>
                <tbody>
                    @forelse ($transactions as $entry)
                        @php($data = $entry['data'])
                        <tr>
                            <th scope="row">{{ $entry['id'] }}</th>
                            @if ($section === 'contributions')
                                <td>{{ ($data['year'] ?? '—').'-'.str_pad($data['month'] ?? '', 2, '0', STR_PAD_LEFT) }}</td>
                                <td>{{ $data['payment_id'] ?? '—' }}</td>
                                <td class="money">{{ $money($data['savings'] ?? null) }}</td><td class="money">{{ $money($data['shares'] ?? null) }}</td>
                                <td>{{ $data['status'] ?? '—' }}</td>
                            @else
                                <td>{{ ($data['date'] ?? null) === '0000-00-00' ? 'Unknown' : ($data['date'] ?? '—') }}</td>
                                <td class="register-name">{{ $data['description'] ?? '—' }}</td><td>{{ $data['payment_id'] ?? '—' }}</td><td>{{ $data['monthly_id'] ?? '—' }}</td>
                                <td class="money">{{ $money($data['dr'] ?? null) }}</td><td class="money">{{ $money($data['cr'] ?? null) }}</td>
                                @if ($section === 'shares')<td>{{ $data['no_shares'] ?? '—' }}</td>@endif
                            @endif
                            <td>
                                @forelse ($entry['issues'] as $issue)
                                    <div>{{ ucfirst(str_replace('_', ' ', $issue)) }}</div>
                                @empty
                                    —
                                @endforelse
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $section === 'contributions' ? 7 : ($section === 'shares' ? 9 : 8) }}">No staged records for this member in this section.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $transactions])
    </section>
@endsection
