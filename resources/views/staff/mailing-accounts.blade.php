@extends('layout')
@section('title', 'Mailing accounts')
@section('content')
    <div class="page-intro">
        <h1>Mailing accounts</h1>
        <a href="{{ route('staff.statement-review.index') }}">Monthly statement review</a>
        <p>Manage the sender for account emails and statements, incoming POP3 or IMAP settings, and scheduled statement delivery.</p>
        <a href="#incoming-mail">Incoming mailbox</a>
        <a href="#automatic-delivery">Automatic delivery</a>
    </div>
    @if (session('status'))<p class="status" role="status">{{ session('status') }}</p>@endif
    @include('form-errors')
    <section class="panel admin-profile">
        <h2>Add SMTP account</h2>
        @php($outgoingInput = old('label') !== null)
        <form class="fields" method="POST" action="{{ route('staff.mailing-accounts.store') }}">
            @csrf
            @foreach ([
                'label' => ['Account label', 'text', '', 80],
                'from_name' => ['Sender name', 'text', 'TASAF SACCOS', 100],
                'from_address' => ['Sender email', 'email', '', 255],
                'host' => ['SMTP hostname', 'text', '', 253],
                'username' => ['SMTP username', 'text', '', 255],
            ] as $field => [$label, $type, $default, $limit])
                <label for="{{ $field }}">{{ $label }}</label>
                <input id="{{ $field }}" name="{{ $field }}" type="{{ $type }}" maxlength="{{ $limit }}" value="{{ $outgoingInput && is_string(old($field)) ? old($field) : $default }}" required>
            @endforeach
            <label for="port">SMTP port</label>
            <input id="port" name="port" type="number" min="1" max="65535" value="{{ $outgoingInput && is_scalar(old('port')) ? old('port') : 587 }}" required>
            <label for="security">Connection security</label>
            <select id="security" name="security">
                <option value="starttls" @selected((!$outgoingInput || old('security') !== 'tls'))>STARTTLS</option>
                <option value="tls" @selected($outgoingInput && old('security') === 'tls')>Implicit TLS</option>
            </select>
            <label for="smtp-password">SMTP password or app password</label>
            <input id="smtp-password" name="password" type="password" maxlength="1024" autocomplete="new-password" required>
            <button class="primary">Save mailing account</button>
        </form>
    </section>
    <section class="panel stacked-panel">
        <h2>Outgoing sender</h2>
        @if ($active === null)
            <p>Using the server’s mail settings.</p>
        @else
            <form method="POST" action="{{ route('staff.mailing-accounts.select') }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="account" value="">
                <button type="submit">Use server mail settings</button>
            </form>
        @endif
        <div class="table-wrap" tabindex="0" role="region" aria-label="Mailing accounts">
            <table>
                <thead><tr><th scope="col">Account</th><th scope="col">Sender email</th><th scope="col">SMTP server</th><th scope="col">Selection</th></tr></thead>
                <tbody>
                    @forelse ($accounts as $account)
                        <tr>
                            <th scope="row">{{ $account->label }}</th><td>{{ $account->from_address }}</td>
                            <td>{{ $account->host }}:{{ $account->port }} · {{ strtoupper($account->security) }}</td>
                            <td>
                                @if ($active === $account->id)
                                    Selected
                                @else
                                    <form method="POST" action="{{ route('staff.mailing-accounts.select') }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="account" value="{{ $account->id }}">
                                        <button type="submit">Use for outgoing mail</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4">No mailing accounts have been added.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $accounts])
    </section>
    <section class="panel stacked-panel admin-profile" id="incoming-mail">
        <h2>Incoming mailbox</h2>
        <p>Save the POP3 or IMAP settings supplied by your email provider. The application stores these settings for future inbox integration.</p>
        @php($incomingInput = old('protocol') !== null)
        <form class="fields" method="POST" action="{{ route('staff.mailing-accounts.incoming') }}">
            @csrf
            @method('PATCH')
            <label for="incoming-protocol">Protocol</label>
            <select id="incoming-protocol" name="protocol">
                <option value="imap" @selected(old('protocol', $incoming->protocol ?? 'imap') === 'imap')>IMAP</option>
                <option value="pop3" @selected(old('protocol', $incoming->protocol ?? 'imap') === 'pop3')>POP3</option>
            </select>
            <label for="incoming-host">Incoming hostname</label>
            <input id="incoming-host" name="host" maxlength="253" value="{{ $incomingInput && is_string(old('host')) ? old('host') : ($incoming->host ?? '') }}" required>
            <label for="incoming-port">Incoming port</label>
            <input id="incoming-port" name="port" type="number" min="1" max="65535" value="{{ $incomingInput && is_scalar(old('port')) ? old('port') : ($incoming->port ?? 993) }}" required>
            <p class="note">Common implicit TLS ports are 993 for IMAP and 995 for POP3. Use the port specified by your provider.</p>
            <label for="incoming-security">Connection security</label>
            <select id="incoming-security" name="security">
                <option value="tls" @selected(($incomingInput ? old('security') : ($incoming->security ?? 'tls')) === 'tls')>Implicit TLS</option>
                <option value="starttls" @selected(($incomingInput ? old('security') : ($incoming->security ?? 'tls')) === 'starttls')>STARTTLS / STLS</option>
            </select>
            <label for="incoming-username">Mailbox username</label>
            <input id="incoming-username" name="username" maxlength="255" value="{{ $incomingInput && is_string(old('username')) ? old('username') : ($incoming->username ?? '') }}" required>
            <label for="incoming-password">Mailbox password or app password</label>
            <input id="incoming-password" name="password" type="password" maxlength="1024" autocomplete="new-password" @required(!$incoming)>
            <button class="primary">Save incoming settings</button>
        </form>
    </section>
    <section class="panel stacked-panel admin-profile" id="automatic-delivery">
        <h2>Automatic statement delivery</h2>
        <p>When enabled, the scheduler sends queued statements for the previous month on the fifth day at 09:00 East Africa Time.</p>
        @unless ($deliveryConfigured)
            <p>Configure and select an outgoing account to run automatic delivery.</p>
        @endunless
        <form class="fields" method="POST" action="{{ route('staff.mailing-accounts.automatic') }}">
            @csrf
            @method('PATCH')
            <label for="automatic-enabled">Schedule status</label>
            <select id="automatic-enabled" name="enabled">
                <option value="0" @selected(!$automatic)>Disabled</option>
                <option value="1" @selected($automatic)>Enabled</option>
            </select>
            <button class="primary">Save schedule setting</button>
        </form>
        @if ($lastAutomaticChange)
            <p class="note">Last changed by {{ $lastAutomaticChange->actor }} on {{ \Carbon\CarbonImmutable::parse($lastAutomaticChange->changed_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT.</p>
        @endif
    </section>
    <section class="panel stacked-panel">
        <h2>Sender history</h2>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Statement sender history">
            <table>
                <thead><tr><th scope="col">Account</th><th scope="col">Changed by</th><th scope="col">Date changed</th></tr></thead>
                <tbody>
                    @forelse ($history as $row)
                        <tr><th scope="row">{{ $row->label ?? 'Server mail settings' }}</th><td>{{ $row->actor }}</td>
                            <td>{{ \Carbon\CarbonImmutable::parse($row->changed_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT</td></tr>
                    @empty
                        <tr><td colspan="3">No sender changes have been recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('staff.pagination', ['page' => $history])
    </section>
@endsection
