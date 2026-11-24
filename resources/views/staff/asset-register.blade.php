@extends('layout')
@section('title', 'Asset register')
@section('content')
    @php($money = fn ($minor) => number_format($minor / 100, 2))
    <div class="page-intro">
        <a href="{{ route('staff.ledger-financial-reports') }}">Financial reports</a>
        <h1>Asset register</h1>
    </div>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @include('form-errors')
    <section class="panel print-hide">
        <h2>{{ $item ? 'Edit asset '.$item->id : 'Register an asset' }}</h2>
        <form class="fields search" method="POST" action="{{ $item ? route('staff.assets.update', $item->id) : route('staff.assets.store') }}">
            @csrf
            @if ($item) @method('PATCH') @endif
            <label for="item">Item</label>
            <input id="item" name="item" maxlength="160" value="{{ is_scalar(old('item')) ? old('item') : ($item->item ?? '') }}" required>
            <label for="value">Original value (TZS)</label>
            <input id="value" name="value" type="number" step="0.01" min="0.01" value="{{ is_scalar(old('value')) ? old('value') : ($item ? number_format($item->value_minor / 100, 2, '.', '') : '') }}" required>
            <label for="depreciation">Accumulated depreciation (TZS)</label>
            <input id="depreciation" name="depreciation" type="number" step="0.01" min="0" value="{{ is_scalar(old('depreciation')) ? old('depreciation') : ($item ? number_format($item->depreciation_minor / 100, 2, '.', '') : '0') }}" required>
            <button class="primary">Save asset</button>
            @if ($item)<a href="{{ route('staff.assets.index') }}">Cancel edit</a>@endif
        </form>
    </section>
    <section class="panel stacked-panel">
        <div class="table-wrap" tabindex="0" role="region" aria-label="Registered assets">
            <table>
                <thead><tr><th scope="col">SN</th><th scope="col">Item</th><th scope="col" class="money">Value (TZS)</th><th scope="col" class="money">Depreciation (TZS)</th><th scope="col" class="money">Book value (TZS)</th></tr></thead>
                <tbody>
                    @forelse ($assets as $asset)
                        <tr>
                            <th scope="row">{{ $asset->id }}</th>
                            <td><a href="{{ route('staff.assets.index', ['edit' => $asset->id]) }}">{{ $asset->item }}</a></td>
                            <td class="money">{{ $money($asset->value_minor) }}</td>
                            <td class="money">{{ $money($asset->depreciation_minor) }}</td>
                            <td class="money">{{ $money($asset->value_minor - $asset->depreciation_minor) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">No assets registered.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $assets])
    </section>
@endsection
