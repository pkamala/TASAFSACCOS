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
    <script src="{{ asset('saccos-tables.js') }}" defer></script>
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
                <span>{{ $branding->name }}</span>
            </a>
            <nav class="main-nav" aria-label="Main navigation">
                @can('access-loan-workflow')
                    <details class="nav-menu">
                        <summary>Operations</summary>
                        <div class="nav-options">
                            @include('nav-link', ['destination' => 'staff.loan-applications.queue', 'label' => 'Loan review'])
                            @include('nav-link', ['destination' => 'staff.loan-batches.index', 'label' => 'Payment lists'])
                            @include('nav-link', ['destination' => 'staff.member-exits.index', 'label' => 'Member exits'])
                            @include('nav-link', ['destination' => 'staff.savings-withdrawals.index', 'label' => 'Withdrawals'])
                            @include('nav-link', ['destination' => 'staff.profile.edit', 'label' => 'My profile'])
                        </div>
                    </details>
                @endcan
                @can('manage-members')
                    <details class="nav-menu">
                        <summary>Administration</summary>
                        <div class="nav-options">
                            @include('nav-link', ['destination' => 'staff.members.index', 'label' => 'Members'])
                            @include('nav-link', ['destination' => 'staff.member-savings.index', 'label' => 'Member savings'])
                            @include('nav-link', ['destination' => 'staff.loan-categories.index', 'label' => 'Loan categories'])
                            @include('nav-link', ['destination' => 'staff.accounts.index', 'label' => 'Chart of accounts'])
                            @include('nav-link', ['destination' => 'staff.loan-ledger.index', 'label' => 'Ledger'])
                            @include('nav-link', ['destination' => 'staff.board-roles.index', 'label' => 'Board roles'])
                            @include('nav-link', ['destination' => 'staff.data-imports.index', 'label' => 'Data imports'])
                            @include('nav-link', ['destination' => 'staff.mailing-accounts.index', 'label' => 'Mailing accounts'])
                            @include('nav-link', ['destination' => 'staff.branding.edit', 'label' => 'Branding'])
                        </div>
                    </details>
                @endcan
                @if (auth()->user()?->member_id)
                    @include('nav-link', ['destination' => 'member.account', 'label' => 'My membership'])
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
