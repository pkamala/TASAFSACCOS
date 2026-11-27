<!doctype html>
<html lang="en" data-theme="{{ $branding->theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ $branding->name }}</title>
    @if ($branding->logo_type)
        <link rel="icon" href="{{ route('branding.logo', $branding->id) }}" type="{{ $branding->logo_type }}">
    @endif
    <link rel="stylesheet" href="{{ asset('saccos.css') }}">
    <script src="{{ asset('saccos-nav.js') }}" defer></script>
    <script src="{{ asset('saccos-table-exports.js') }}?v=4" defer></script>
    <script src="{{ asset('saccos-tables.js') }}?v=4" defer></script>
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to content</a>
    <header class="site-header">
        <div class="site-bar">
            <a href="{{ route('loan.calculator') }}" class="brand">
                @if ($branding->logo_type)
                    <img class="brand-logo" src="{{ route('branding.logo', $branding->id) }}" alt="">
                @else
                    <span class="brand-mark" aria-hidden="true">{{ $branding->mark }}</span>
                @endif
                <span class="brand-name">{{ $branding->name }}</span>
            </a>
            <nav class="main-nav" aria-label="Main navigation">
                @can('access-task-inbox')
                    <a href="{{ route('staff.tasks.index') }}" data-task-count-url="{{ route('staff.tasks.count', [], false) }}" @if (request()->routeIs('staff.tasks.index')) aria-current="page" @endif>Tasks <span class="task-count" data-task-count hidden></span></a>
                @endcan
                @can('access-loan-workflow')
                    <details class="nav-menu">
                        <summary>Operations</summary>
                        <div class="nav-options">
                            @include('nav-link', ['destination' => 'staff.loan-applications.queue', 'label' => 'Loan review'])
                            @include('nav-link', ['destination' => 'staff.loan-batches.index', 'label' => 'Payment lists'])
                            @can('manage-members')
                                @include('nav-link', ['destination' => 'staff.agm.index', 'label' => 'AGM registration'])
                            @endcan
                            @include('nav-link', ['destination' => 'staff.member-exits.index', 'label' => 'Member exits'])
                            @include('nav-link', ['destination' => 'staff.savings-withdrawals.index', 'label' => 'Withdrawals'])
                            @include('nav-link', ['destination' => 'staff.profile.edit', 'label' => 'My profile'])
                        </div>
                    </details>
                @endcan
                @cannot('access-loan-workflow')
                    @can('access-loan-applications')
                        @include('nav-link', ['destination' => 'staff.loan-applications.queue', 'label' => 'Loan review'])
                    @endcan
                    @can('submit-loans')
                        @include('nav-link', ['destination' => 'staff.loan-intake', 'label' => 'Loan intake'])
                    @endcan
                @endcannot
                @can('manage-members')
                    <details class="nav-menu">
                        <summary>Products</summary>
                        <div class="nav-options">
                            @foreach (['savings' => 'Savings', 'deposits' => 'Deposits', 'shares' => 'Shares'] as $fundProduct => $label)
                                <a href="{{ route('staff.member-savings.index', ['product' => $fundProduct]) }}"
                                    @if (request()->routeIs('staff.member-savings.index') && request()->query('product', 'savings') === $fundProduct) aria-current="page" @endif>{{ $label }}</a>
                            @endforeach
                            @include('nav-link', ['destination' => 'staff.loan-register', 'label' => 'Loans'])
                        </div>
                    </details>
                    <details class="nav-menu">
                        <summary>Reports</summary>
                        <div class="nav-options">
                            @include('nav-link', ['destination' => 'staff.reports.index', 'label' => 'Reporting overview'])
                            @include('nav-link', ['destination' => 'staff.ledger-financial-reports', 'label' => 'Financial statements'])
                            @include('nav-link', ['destination' => 'staff.loan-ledger.index', 'label' => 'Trial balance'])
                            @include('nav-link', ['destination' => 'staff.cash-book.index', 'label' => 'Cash book'])
                            @include('nav-link', ['destination' => 'staff.loan-register', 'label' => 'Loan register'])
                            @include('nav-link', ['destination' => 'staff.member-savings.index', 'label' => 'Member savings'])
                        </div>
                    </details>
                    <details class="nav-menu">
                        <summary>Migration</summary>
                        <div class="nav-options">
                            @include('nav-link', ['destination' => 'staff.cutover-checklist', 'label' => 'Cutover checklist'])
                            @include('nav-link', ['destination' => 'staff.payroll-corrections.index', 'label' => 'Payroll corrections'])
                            @include('nav-link', ['destination' => 'staff.payroll-loan-reconciliation.index', 'label' => 'Payroll loan reconciliation'])
                            @include('nav-link', ['destination' => 'staff.bank-reconciliation.index', 'label' => 'Bank reconciliation'])
                            @include('nav-link', ['destination' => 'staff.data-imports.index', 'label' => 'Data imports'])
                            @include('nav-link', ['destination' => 'staff.ledger-opening.index', 'label' => 'Opening journal'])
                            @include('nav-link', ['destination' => 'staff.verified-balance-import.index', 'label' => 'Verify member balances'])
                            @include('nav-link', ['destination' => 'staff.verified-legacy-loans.index', 'label' => 'Verify legacy loans'])
                            @include('nav-link', ['destination' => 'staff.active-loans.index', 'label' => 'Migrate active loans'])
                        </div>
                    </details>
                    <details class="nav-menu">
                        <summary>Administration</summary>
                        <div class="nav-options">
                            @include('nav-link', ['destination' => 'staff.admin-accounts.index', 'label' => 'Admin accounts'])
                            @include('nav-link', ['destination' => 'staff.members.index', 'label' => 'Members'])
                            @include('nav-link', ['destination' => 'staff.loan-categories.index', 'label' => 'Loan categories'])
                            @include('nav-link', ['destination' => 'staff.accounts.index', 'label' => 'Chart of accounts'])
                            @include('nav-link', ['destination' => 'staff.budgets.index', 'label' => 'Annual budget'])
                            @include('nav-link', ['destination' => 'staff.assets.index', 'label' => 'Asset register'])
                            @include('nav-link', ['destination' => 'staff.board-roles.index', 'label' => 'Board roles'])
                            @include('nav-link', ['destination' => 'staff.mailing-accounts.index', 'label' => 'Mailing accounts'])
                            @include('nav-link', ['destination' => 'staff.branding.edit', 'label' => 'Branding'])
                        </div>
                    </details>
                @endcan
                @if (auth()->user()?->member_id)
                    <a href="{{ route('member.notifications.index') }}" data-task-count-url="{{ route('member.notifications.count', [], false) }}" @if (request()->routeIs('member.notifications.index')) aria-current="page" @endif>Notifications <span class="task-count" data-task-count hidden></span></a>
                    @include('nav-link', ['destination' => 'member.account', 'label' => 'My membership'])
                    @include('nav-link', ['destination' => 'member.agm.index', 'label' => 'AGM'])
                @endif
                @auth
                    <form method="POST" action="{{ route('staff.logout') }}">@csrf<button class="quiet">Sign out</button></form>
                @else
                    <a class="tonal" href="{{ route('login') }}">Sign in</a>
                @endauth
            </nav>
        </div>
    </header>
    <main class="container" id="main-content" tabindex="-1">
        @if (config('app.demo_mode'))<p class="status" role="status">Demo environment · Practice transactions and provisional opening balances</p>@endif
        @yield('content')</main>
</body>
</html>
