@if ($joiningDate)
    @php
        $joined = \Carbon\CarbonImmutable::parse($joiningDate, 'Africa/Dar_es_Salaam');
        $today = \Carbon\CarbonImmutable::today('Africa/Dar_es_Salaam');
        $through = $exitedOn ? min($today, \Carbon\CarbonImmutable::parse($exitedOn, 'Africa/Dar_es_Salaam')) : $today;
    @endphp
    <p>Joined {{ $joined->format('d M Y') }} · {{ $through->lt($joined) ? 'Membership has not begun' : $joined->diffForHumans($through, ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 3]) }} @if ($exitedOn && ! $through->lt($joined)) at exit @endif</p>
@else
    <p>Joining date unavailable.</p>
@endif
