@extends('layout')
@section('title', 'Loan calculator')
@section('content')
    <div class="page-intro calculator-intro">
        <h1>Loan calculator</h1>
        <p>Estimate your monthly repayments and see how your loan balance changes over the term.</p>
    </div>
    <div class="calculator">
        <section class="panel details" aria-labelledby="details">
            <h2 id="details">Loan details</h2>
            @include('form-errors')
            @if ($examplePricing)
                <p class="note">This category uses example pricing until the administrator sets its terms.</p>
            @endif
            <form method="GET" action="{{ route('loan.schedule') }}" class="fields">
                @include('staff.loan-applications.category-picker')
                <label for="amount">Loan amount (TZS)</label>
                <input type="number" id="amount" name="amount" min="100" max="100000000000" step="0.01" value="{{ $inputs['amount'] }}" required>
                <label for="rate">Annual interest rate (%)</label>
                <input id="rate" value="{{ $annualRate }}" readonly>
                <label for="months">Loan term (months)</label>
                <input type="number" id="months" name="months" min="1" max="120" value="{{ $inputs['months'] }}" required>
                <label for="method">Interest method</label>
                <input id="method" value="{{ $method === 'flat' ? 'Flat rate' : 'Reducing balance' }}" readonly>
                <label for="first_due_date">First payment date <small>Optional</small></label>
                <input type="date" id="first_due_date" name="first_due_date" min="1900-01-01" max="2089-12-31" value="{{ $inputs['first_due_date'] }}">
                <fieldset class="fields charges">
                    <legend>Financed charges (TZS)</legend>
                    @foreach ($chargeLabels as $field => $label)
                        <label for="{{ $field }}">{{ $label }} @if ($field === 'insurance') <small>{{ $insuranceRate }}% of the loan amount</small> @endif</label>
                        <input id="{{ $field }}" value="{{ $charges[$field] === null ? '—' : number_format($charges[$field] / 100, 2) }}" readonly>
                    @endforeach
                </fieldset>
                <button class="primary">Calculate schedule</button>
            </form>
        </section>
        @if ($schedule)
            <section class="panel results" aria-labelledby="results">
                <div class="result-heading"><h2 id="results">Repayment schedule</h2><span>TZS</span></div>
                <dl class="summary">
                    <div><dt>First payment</dt><dd>{{ number_format($schedule['rows'][0]['payment'] / 100, 2) }}</dd></div>
                    <div><dt>Total interest</dt><dd>{{ number_format($schedule['interest'] / 100, 2) }}</dd></div>
                    <div><dt>Total repayment</dt><dd>{{ number_format($schedule['total'] / 100, 2) }}</dd></div>
                </dl>
                @if ($financing['total_charges'] > 0)
                    <dl class="cost-breakdown">
                        <div><dt>Loan amount</dt><dd>{{ number_format($financing['amount'] / 100, 2) }}</dd></div>
                        @foreach ($financing['charges'] as $field => $charge)
                            @if ($charge > 0)
                                <div><dt>{{ $chargeLabels[$field] }}</dt><dd>{{ number_format($charge / 100, 2) }}</dd></div>
                            @endif
                        @endforeach
                        <div><dt>Total charges</dt><dd>{{ number_format($financing['total_charges'] / 100, 2) }}</dd></div>
                        <div><dt>Total financed</dt><dd>{{ number_format($schedule['rows'][0]['opening'] / 100, 2) }}</dd></div>
                    </dl>
                @endif
                <p class="note">Interest applies to the loan amount plus any financed charges. Installments may differ slightly due to rounding.</p>
                <div class="table-wrap" tabindex="0" role="region" aria-label="Monthly repayment schedule">
                    <table data-table-pages>
                        <thead><tr><th scope="col">Month</th>@if ($inputs['first_due_date'])<th scope="col">Due date</th>@endif<th scope="col" class="money">Opening balance</th><th scope="col" class="money">Principal</th><th scope="col" class="money">Interest</th><th scope="col" class="money">Payment</th><th scope="col" class="money">Closing balance</th></tr></thead>
                        <tbody>
                        @foreach ($schedule['rows'] as $row)
                            <tr><th scope="row">{{ $row['month'] }}</th>@if ($row['date'])<td>{{ $row['date'] }}</td>@endif
                                @foreach (['opening', 'principal', 'interest', 'payment', 'closing'] as $field)
                                    <td class="money @if($field === 'payment') emphasis @endif">{{ number_format($row[$field] / 100, 2) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
@endsection
