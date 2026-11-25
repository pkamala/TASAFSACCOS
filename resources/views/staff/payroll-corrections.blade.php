@extends('layout')
@section('title', 'Payroll corrections')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.loan-batches.index') }}">Payment lists</a>
        <h1>Payroll corrections</h1>
        <p>Review the source entries before importing contributions. Record how each issue was corrected after checking the source or receiving confirmation.</p>
    </div>
    @if (session('status')) <p class="status" role="status">{{ session('status') }}</p> @endif
    @include('form-errors')
    <section class="panel stacked-panel">
        <form method="GET" action="{{ route('staff.payroll-corrections.index') }}" class="fields search">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="open" @selected($status === 'open')>Open</option>
                <option value="resolved" @selected($status === 'resolved')>Resolved</option>
            </select>
            <button class="primary">Filter</button>
        </form>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Payroll corrections">
            <table>
                <thead>
                    <tr><th scope="col">Month</th><th scope="col">Entry</th><th scope="col">Source</th><th scope="col">Issue</th><th scope="col">Resolution</th></tr>
                </thead>
                <tbody>
                    @forelse ($entries as $entry)
                        <tr>
                            <td><time datetime="{{ substr($entry->payroll_month, 0, 7) }}">{{ \Carbon\CarbonImmutable::parse($entry->payroll_month)->format('M Y') }}</time></td>
                            <th scope="row">{{ $entry->entry }}</th>
                            <td>
                                {{ $entry->source_file ?? '—' }}
                                @if ($entry->source_row)<br>Row {{ $entry->source_row }}@endif
                            </td>
                            <td>{{ $entry->issue }}</td>
                            <td>
                                @if ($entry->resolved_at)
                                    {{ $entry->resolution }}
                                    <br><small>{{ $entry->resolver }} · {{ \Carbon\CarbonImmutable::parse($entry->resolved_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT</small>
                                @else
                                    <details>
                                        <summary>Record resolution</summary>
                                        <form method="POST" action="{{ route('staff.payroll-corrections.resolve', $entry->id) }}" class="fields">
                                            @csrf
                                            @method('PATCH')
                                            <label for="resolution-{{ $entry->id }}">Resolution note</label>
                                            <textarea id="resolution-{{ $entry->id }}" name="resolution" maxlength="500" required></textarea>
                                            <button class="primary">Mark resolved</button>
                                        </form>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5">No {{ $status }} payroll corrections.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $entries])
    </section>
@endsection
