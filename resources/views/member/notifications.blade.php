@extends('layout')
@section('title', 'Notifications')
@section('content')
    <div class="page-intro">
        <a href="{{ route('member.account') }}">My membership</a>
        <h1>Notifications</h1>
    </div>
    <section class="panel">
        <ul>
            @forelse ($updates as $update)
                <li>
                    @if ($update->kind === 'sponsorship')
                        <a href="{{ route('member.account') }}#sponsorships-heading">Confirm sponsorship for loan application {{ $update->reference }}</a>
                    @elseif ($update->kind === 'withdrawal')
                        <a href="{{ route('member.account') }}#withdrawals-heading">Withdrawal {{ $update->reference }} · {{ ucfirst($update->status) }}</a>
                    @else
                        <a href="{{ route('member.account') }}#member-loans-heading">Loan {{ $update->reference }} · {{ ucfirst($update->status) }}</a>
                    @endif
                    <small>{{ \Carbon\CarbonImmutable::parse($update->occurred_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y') }}</small>
                </li>
            @empty
                <li>No notifications yet.</li>
            @endforelse
        </ul>
        @include('staff.pagination', ['page' => $updates])
    </section>
@endsection
