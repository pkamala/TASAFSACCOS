@extends('layout')
@section('title', 'Member profile')
@section('content')
    <div class="page-intro">
        <a href="{{ route('staff.members.index') }}">All members</a>
        <h1>{{ $profile->first_name }} {{ $profile->last_name }}</h1>
        <p>{{ $profile->registration_no }} · {{ ucfirst($profile->status) }}</p>
        @include('member.tenure', ['joiningDate' => $profile->joining_date, 'exitedOn' => $exitedOn])
        <a href="{{ route('staff.members.next-of-kin.index', $profile->id) }}">Next of kin</a>
        <a href="{{ route('staff.loan-applications.index', $profile->id) }}">Loan applications</a>
        <a href="{{ route('staff.member-funds.index', $profile->id) }}">Savings and shares</a>
        <a href="{{ route('staff.member-balances.index', $profile->id) }}">Verified savings and shares</a>
        <a href="{{ route('staff.member-exits.create', $profile->id) }}">Membership exit</a>
        @if ($profile->legacy_member_id)
            <a href="{{ route('staff.legacy-loans.index', $profile->id) }}">Legacy loans and repayments</a>
            <a href="{{ route('staff.legacy-member-transactions', $profile->id) }}">Legacy savings and contributions</a>
        @endif
    </div>
    @if (session('status')) <p role="status">{{ session('status') }}</p> @endif
    <div class="profile-grid">
        @if ($profile->status !== 'exited')
            <section class="panel">
                <h2>Membership status</h2>
                <form method="POST" action="{{ route('staff.members.status.update', $profile->id) }}" class="fields">
                    @csrf
                    @method('PATCH')
                    <label for="membership_status">Status</label>
                    <select id="membership_status" name="membership_status" required>
                        <option value="active" @selected(old('membership_status', $profile->status) === 'active')>Active</option>
                        <option value="inactive" @selected(old('membership_status', $profile->status) === 'inactive')>Inactive</option>
                    </select>
                    <label for="status_reason">Reason for status change</label>
                    <input id="status_reason" name="status_reason" maxlength="500" required>
                    <button class="primary">Save membership status</button>
                </form>
            </section>
        @endif
        @if ($login && ! $login->is_staff_admin && ($profile->status === 'active' || $login->loan_workflow_role))
            <section class="panel">
                <h2>Loan workflow role</h2>
                <form method="POST" action="{{ route('staff.members.loan-role.update', $profile->id) }}" class="fields">
                    @csrf
                    @method('PATCH')
                    <label for="loan_workflow_role">Assigned role</label>
                    <select id="loan_workflow_role" name="loan_workflow_role">
                        <option value="">No assigned role</option>
                        @if ($profile->status === 'active')
                            @foreach (\App\LoanApproval::Roles as $role => $label)
                                <option value="{{ $role }}" @selected(old('loan_workflow_role', $login->loan_workflow_role) === $role)>{{ $label }}</option>
                            @endforeach
                        @endif
                    </select>
                    <label for="role_reason">Reason for role change</label>
                    <input id="role_reason" name="role_reason" maxlength="500" required>
                    <button class="primary">Save loan role</button>
                </form>
            </section>
        @endif
        <section class="panel">
            <h2>Profile details</h2>
            <p>{{ $profile->email }}</p>
            @include('form-errors')
            <form method="POST" action="{{ route('staff.members.update', $profile->id) }}" class="fields">
                @csrf
                @method('PATCH')
                @foreach (['first_name' => 'First name', 'middle_name' => 'Middle name', 'last_name' => 'Last name', 'phone' => 'Phone', 'joining_date' => 'Joining date'] as $field => $label)
                    <label for="{{ $field }}">{{ $label }}</label>
                    <input id="{{ $field }}" name="{{ $field }}" type="{{ match($field) { 'joining_date' => 'date', 'phone' => 'tel', default => 'text' } }}" value="{{ is_string(old($field, $profile->$field)) ? old($field, $profile->$field) : '' }}" @required(in_array($field, ['first_name', 'last_name'], true))>
                @endforeach
                <button class="primary">Save profile</button>
            </form>
        </section>
        <section class="panel">
            <h2>Recurring payroll contributions</h2>
            <p>Set the agreed monthly savings and share deductions. Enter zero for any deduction that should be omitted from new payroll lists.</p>
            <form method="POST" action="{{ route('staff.members.payroll.update', $profile->id) }}" class="fields">
                @csrf
                @method('PATCH')
                @foreach (['payroll_savings' => 'Monthly savings (TZS)', 'payroll_shares' => 'Monthly shares (TZS)'] as $field => $label)
                    <label for="{{ $field }}">{{ $label }}</label>
                    <input id="{{ $field }}" name="{{ $field }}" type="number" min="0" max="999999999999.99" step="0.01"
                        value="{{ is_string(old($field)) ? old($field) : number_format($profile->{$field.'_minor'} / 100, 2, '.', '') }}" required>
                @endforeach
                <label for="payroll-reference">Approval or change reference</label>
                <textarea id="payroll-reference" name="payroll_reference" maxlength="500" required>{{ is_string(old('payroll_reference')) ? old('payroll_reference') : '' }}</textarea>
                <button class="primary">Save payroll contributions</button>
            </form>
        </section>
        <section class="panel" id="member-login">
            <h2>Member login</h2>
            @if ($login?->is_staff_admin)
                <p>This account has administrator access. Use administrator password recovery.</p>
            @elseif ($login || $profile->status === 'active')
                <p>{{ $login?->email ?? $profile->email }} · {{ $login ? ($login->local_setup_pending ? 'Setup pending' : 'Account created') : 'No login yet' }}</p>
                <p>A one-time link will be emailed to this address.</p>
                <form method="POST" action="{{ route('staff.members.login-link', $profile->id) }}">
                    @csrf
                    <button class="primary">{{ $login && ! $login->local_setup_pending ? 'Email password reset link' : 'Email account setup link' }}</button>
                </form>
            @else
                <p>New logins require active membership.</p>
            @endif
        </section>
        <section class="panel">
            <h2>Profile change history</h2>
            @forelse ($history as $entry)
                <article class="history-entry">
                    <strong>{{ $entry->actor }}</strong>
                    <time datetime="{{ \Carbon\CarbonImmutable::parse($entry->recorded_at)->toIso8601String() }}">{{ \Carbon\CarbonImmutable::parse($entry->recorded_at)->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }} EAT</time>
                    <ul>
                        @foreach (json_decode($entry->changes, true, flags: JSON_THROW_ON_ERROR) as $field => $change)
                            <li>{{ ucfirst(str_replace('_', ' ', $field)) }}: {{ $change['before'] ?? 'Blank' }} → {{ $change['after'] ?? 'Blank' }}</li>
                        @endforeach
                    </ul>
                </article>
            @empty
                <p>No profile edits recorded.</p>
            @endforelse
            @include('staff.pagination', ['page' => $history])
        </section>
    </div>
@endsection
