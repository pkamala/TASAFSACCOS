@extends('layout')
@section('title', 'Legacy loan schedule')
@section('content')
    @php($loan = json_decode($record->data, true, flags: JSON_THROW_ON_ERROR))
    <div class="page-intro">
        <a href="{{ route('staff.legacy-loans.index', $profile->id) }}">Member’s legacy loans</a>
        <h1>Legacy loan {{ $loan['loan_code'] ?: $record->source_id }}</h1>
        <p>{{ $profile->first_name }} {{ $profile->last_name }} · {{ $profile->registration_no }}</p>
    </div>
    <section class="panel">
        <h2>Original repayment schedule</h2>
        <p>Scheduled amounts from the export. Current balances require reconciliation.</p>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Original repayment schedule">
            <table>
                <thead><tr><th scope="col">Month</th><th scope="col">Due date</th><th scope="col" class="money">Principal (TZS)</th><th scope="col" class="money">Interest (TZS)</th><th scope="col">Status in export</th></tr></thead>
                <tbody>
                    @forelse ($schedule as $entry)
                        @php($row = json_decode($entry->data, true, flags: JSON_THROW_ON_ERROR))
                        <tr>
                            <th scope="row">{{ $row['month'] }}</th>
                            <td>{{ $row['duedate'] === '0000-00-00' ? 'Unknown' : ($row['duedate'] ?? '—') }}</td>
                            <td class="money">{{ is_numeric($row['monthprinciple']) ? number_format((float) $row['monthprinciple'], 2) : '—' }}</td>
                            <td class="money">{{ is_numeric($row['monthinterest']) ? number_format((float) $row['monthinterest'], 2) : '—' }}</td>
                            <td>{{ $row['status'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">No staged installments for this loan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $schedule])
    </section>
    <section class="panel stacked-panel">
        <h2>Legacy repayment records</h2>
        <p>Review records with a resolved loan link. Conflicting links are held in staging.</p>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Legacy repayment records">
            <table>
                <thead><tr><th scope="col">Date</th><th scope="col">Payment reference</th><th scope="col">Schedule ID</th><th scope="col" class="money">Principal (TZS)</th><th scope="col" class="money">Interest (TZS)</th><th scope="col" class="money">Amount (TZS)</th></tr></thead>
                <tbody>
                    @forelse ($repayments as $entry)
                        @php($row = json_decode($entry->data, true, flags: JSON_THROW_ON_ERROR))
                        <tr>
                            <th scope="row">{{ $row['paymentdate'] === '0000-00-00' ? 'Unknown' : ($row['paymentdate'] ?? '—') }}</th>
                            <td>{{ $row['payment_id'] ?? '—' }}</td>
                            <td>{{ $entry->confirmed_schedule_id ?? $row['loanschedule_id'] ?? '—' }}</td>
                            @foreach (['principlepayed', 'interestpayed', 'amountpayed'] as $amount)
                                <td class="money">{{ is_numeric($row[$amount]) ? number_format((float) $row[$amount], 2) : '—' }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="6">No linked repayment records for this loan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $repayments])
    </section>
@endsection
