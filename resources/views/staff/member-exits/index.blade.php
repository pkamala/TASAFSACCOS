@extends('layout')
@section('title', 'Member exits')
@section('content')
    <div class="page-intro"><h1>Member exits</h1><p>Review requests and track settlement before deactivating a membership.</p></div>
    <section class="panel">
        <div class="table-wrap"><table>
            <thead><tr><th scope="col">Exit</th><th scope="col">Member</th><th scope="col">Requested at</th><th scope="col">Status</th></tr></thead>
            <tbody>
                @forelse ($exits as $exit)
                    <tr><th scope="row"><a href="{{ route('staff.member-exits.show', $exit->id) }}">{{ $exit->id }}</a></th><td>{{ $exit->registration_no }} · {{ $exit->first_name }} {{ $exit->last_name }}</td><td>{{ $exit->requested_at }}</td><td>{{ ucfirst($exit->status) }}</td></tr>
                @empty
                    <tr><td colspan="4">No exit requests.</td></tr>
                @endforelse
            </tbody>
        </table></div>
        @include('staff.pagination', ['page' => $exits])
    </section>
@endsection
