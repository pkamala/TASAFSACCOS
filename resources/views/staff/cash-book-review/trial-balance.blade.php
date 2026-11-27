@extends('layout')
@section('title', 'Trial balance preview')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.cash-book-review.index') }}">Cashbook review</a>
        <h1>Trial balance preview</h1>
        @if ($source)
            <p>Opening trial balance as at {{ $source->as_of }} · {{ $source->source_file }}</p>
            <p>The preview includes {{ $counts->get('approved', 0) }} approved and {{ $counts->get('posted', 0) }} posted workbook journals. Pending and matched rows are excluded. Use Reports for the posted ledger trial balance.</p>
        @endif
    </div>
    @if (! $source)
        <p>No workbook opening balances have been loaded.</p>
    @else
        @php
            $money = fn (int $minor): string => number_format($minor / 100, 2);
            $difference = $totals['opening_debit'] - $totals['opening_credit'];
        @endphp
        <section class="panel stacked-panel">
            @if ($unmapped)
                <p>Opening lines requiring an account mapping · {{ $unmapped }}</p>
            @endif
            @if ($difference !== 0)
                <p>Opening imbalance · TZS {{ $money(abs($difference)) }} {{ $difference > 0 ? 'debit' : 'credit' }} excess</p>
            @endif
            <div class="table-wrap" tabindex="0" role="region" aria-label="Trial balance preview in Tanzanian shillings">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Account</th><th scope="col" class="money">Opening debit</th><th scope="col" class="money">Opening credit</th>
                            <th scope="col" class="money">Journal debits</th><th scope="col" class="money">Journal credits</th>
                            <th scope="col" class="money">Preview debit</th><th scope="col" class="money">Preview credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lines as $line)
                            <tr>
                                <th scope="row">
                                    @if ($line['code']){{ $line['code'] }} · @endif{{ $line['name'] }}
                                    @if ($line['unmapped']) <small>· Needs mapping</small>@endif
                                </th>
                                @foreach (array_keys($totals) as $column)
                                    <td class="money">{{ $money($line[$column]) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr><th scope="row">Total (TZS)</th>
                            @foreach ($totals as $minor)<td class="money">{{ $money($minor) }}</td>@endforeach
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    @endif
@endsection
