@extends('layout')
@section('title', 'Legacy loans')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.members.show', $profile->id) }}">Member profile</a>
        <h1>Legacy loans</h1>
        <p>{{ $profile->first_name }} {{ $profile->last_name }} · {{ $profile->registration_no }}</p>
    </div>
    <section class="panel">
        <div class="table-wrap" tabindex="0" role="region" aria-label="Legacy loans">
            <table>
                <thead><tr><th scope="col">Loan reference</th><th scope="col">Status in export</th><th scope="col" class="money">Original principal (TZS)</th></tr></thead>
                <tbody>
                    @forelse ($loans as $loan)
                        @php($data = json_decode($loan->data, true, flags: JSON_THROW_ON_ERROR))
                        <tr>
                            <th scope="row"><a href="{{ route('staff.legacy-loans.show', [$profile->id, $loan->source_id]) }}">{{ $data['loan_code'] ?: $loan->source_id }}</a></th>
                            <td>{{ $data['loan_status'] ?? '—' }}</td>
                            <td class="money">{{ is_numeric($data['principle']) ? number_format((float) $data['principle'], 2) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3">No staged legacy loans for this member.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $loans])
    </section>
@endsection
