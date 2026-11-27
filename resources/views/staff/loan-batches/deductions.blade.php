@extends('layout')
@section('title', 'Review payroll deductions')
@section('content')
    @php($money = fn ($value) => number_format($value / 100, 2))
    <div class="page-intro">
        <a href="{{ route('staff.loan-batches.show', $record->id) }}">Approved payroll list {{ $record->id }}</a>
        <h1>Review payroll deductions</h1>
        <p>Payroll month {{ substr($record->period, 0, 7) }} · Approved total TZS {{ $money($planned) }}</p>
    </div>
    @include('form-errors')
    <section class="panel stacked-panel">
        <h2>Compare with TASAF Accounts</h2>
        <p><a href="{{ route('staff.payroll-deductions.template', $record->id) }}">Download this payroll’s deduction template</a>. Fill in every actual amount, including 0 for missed deductions. Keep each loan’s principal and interest separate and retain the registration numbers as text.</p>
        <form method="POST" action="{{ route('staff.payroll-deductions.preview', $record->id) }}" enctype="multipart/form-data" class="fields">
            @csrf
            <label for="deductions">Actual deductions (CSV or single-sheet Excel)</label>
            <input id="deductions" name="deductions" type="file" accept=".csv,.xlsx" required>
            <button class="primary">Preview deductions</button>
        </form>
    </section>

    @if ($preview)
        <section class="panel stacked-panel" id="deduction-preview">
            <h2>Deduction differences</h2>
            <dl class="summary">
                <div><dt>Planned (TZS)</dt><dd>{{ $money($planned) }}</dd></div>
                <div><dt>Actual (TZS)</dt><dd>{{ $money($actual) }}</dd></div>
                <div><dt>Actual less planned (TZS)</dt><dd>{{ $money($actual - $planned) }}</dd></div>
            </dl>
            @if ($differences->isNotEmpty())
                <div class="table-wrap" tabindex="0" role="region" aria-label="Payroll deduction differences">
                    <table data-table-pages>
                        <thead><tr><th scope="col">Member</th><th scope="col">Deduction</th><th class="money" scope="col">Planned (TZS)</th><th class="money" scope="col">Actual (TZS)</th><th class="money" scope="col">Difference (TZS)</th><th scope="col">Status</th></tr></thead>
                        <tbody>
                            @foreach ($differences as $row)
                                <tr data-member-status="{{ $memberStatuses[$row['member_id']] ?? '' }}"><th scope="row">{{ $registrationNumbers[$row['member_id']] }}</th><td>{{ $row['label'] }}</td>
                                    <td class="money">{{ $money($row['planned']) }}</td><td class="money">{{ $money($row['actual']) }}</td>
                                    <td class="money">{{ $money($row['difference']) }}</td><td>{{ $row['status'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p>Every deduction matches the approved list.</p>
            @endif
            @if ($differences->contains('status', 'Excess'))
                <p>Resolve excess deductions with TASAF Accounts and upload the corrected result before confirming payroll.</p>
            @endif
        </section>
    @endif

    @if (! $differences->contains('status', 'Excess'))
        <section class="panel stacked-panel">
            <h2>{{ $preview ? 'Confirm reviewed deductions' : 'Confirm an exact match' }}</h2>
            @unless ($preview)
                <p>Use this option when you have checked every deduction against the TASAF Accounts result and all amounts match.</p>
            @endunless
            <form method="POST" action="{{ route('staff.loan-batches.confirm', $record->id) }}" class="fields">
                @csrf
                @if ($preview)
                    <input type="hidden" name="deduction_token" value="{{ $preview['token'] }}">
                @else
                    <label><input type="checkbox" name="confirm_exact" value="1" required> I verified that all deductions match the approved list.</label>
                @endif
                <label for="effective_date">Actual repayment date</label>
                <input id="effective_date" name="effective_date" type="date" value="{{ is_string(old('effective_date')) ? old('effective_date') : '' }}" required>
                <label for="payroll_reference">TASAF Accounts payroll reference</label>
                <input id="payroll_reference" name="payroll_reference" maxlength="100" value="{{ is_string(old('payroll_reference')) ? old('payroll_reference') : '' }}" required>
                @if ($differences->isNotEmpty())
                    <label for="deduction_note">Finance review note for the missed or partial deductions</label>
                    <textarea id="deduction_note" name="deduction_note" maxlength="500" rows="3" required>{{ is_string(old('deduction_note')) ? old('deduction_note') : '' }}</textarea>
                    <p>Unpaid installments remain outstanding and can be reviewed for inclusion in a later payroll list.</p>
                @endif
                <button class="primary">Confirm payroll deductions</button>
            </form>
        </section>
    @endif
@endsection
