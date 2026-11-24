<?php

namespace App\Providers;

use App\Models\User;
use App\SiteBranding;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('layout', function (\Illuminate\View\View $view): void {
            $view->with('branding', app(SiteBranding::class)->current());
        });

        Gate::define('review-loans', fn (User $user): bool => $user->isBoardOfficer());
        Gate::define('access-loan-workflow', fn (User $user): bool => $user->is_staff_admin === true || $user->isBoardOfficer());
        Gate::define('submit-loans', fn (User $user): bool => $user->is_staff_admin === true || $user->hasLoanRole('staff'));
        Gate::define('review-loan-applications', fn (User $user): bool => $user->isBoardOfficer() || $user->hasLoanRole('committee'));
        Gate::define('access-loan-applications', fn (User $user): bool => $user->canAny(['access-loan-workflow', 'submit-loans', 'review-loan-applications']));
        Gate::define('access-task-inbox', fn (User $user): bool => $user->can('access-loan-workflow') || $user->hasLoanRole('committee'));
        Gate::define('manage-members', fn (User $user): bool => $user->is_staff_admin === true);
    }
}
