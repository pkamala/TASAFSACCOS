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
                            <th scope="col">Account code</th>
                            <th scope="col">Description</th>
                            <th scope="col" class="money">DR</th>
                            <th scope="col" class="money">CR</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lines as $line)
                            <tr>
                                <td>{{ $line['code'] ?? '—' }}</td>
                                <th scope="row">{{ $line['name'] }} @if ($line['unmapped'])<small>· Needs mapping</small>@endif</th>
                                <td class="money">{{ $line['preview_debit'] ? $money($line['preview_debit']) : '—' }}</td>
                                <td class="money">{{ $line['preview_credit'] ? $money($line['preview_credit']) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th scope="row" colspan="2">Total (TZS)</th>
                            <td class="money">{{ $money($totals['preview_debit']) }}</td>
                            <td class="money">{{ $money($totals['preview_credit']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <details>
                <summary>Opening balances and journal movements</summary>
                <div class="table-wrap" tabindex="0" role="region" aria-label="Opening balance and journal movement breakdown">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">Account</th>
                                <th scope="col" class="money">Opening DR</th>
                                <th scope="col" class="money">Opening CR</th>
                                <th scope="col" class="money">Journal DR</th>
                                <th scope="col" class="money">Journal CR</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($lines as $line)
                                <tr>
                                    <th scope="row">@if ($line['code']){{ $line['code'] }} · @endif{{ $line['name'] }}</th>
                                    @foreach (['opening_debit', 'opening_credit', 'movement_debit', 'movement_credit'] as $column)
                                        <td class="money">{{ $money($line[$column]) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <th scope="row">Total (TZS)</th>
                                @foreach (['opening_debit', 'opening_credit', 'movement_debit', 'movement_credit'] as $column)
                                    <td class="money">{{ $money($totals[$column]) }}</td>
                                @endforeach
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </details>
        </section>
    @endif
@endsection
