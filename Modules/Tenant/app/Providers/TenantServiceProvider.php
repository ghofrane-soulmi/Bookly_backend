<?php

namespace Modules\Tenant\Providers;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Auth;
use Modules\Tenant\Models\Scopes\TenantScope;
use Modules\Tenant\Support\Tenant;
use Nwidart\Modules\Support\ModuleServiceProvider;

class TenantServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Tenant';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'tenant';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    // protected array $commands = [];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->singleton(Tenant::class);
    }

    public function boot(): void
    {
        parent::boot();

        // JWT/session auth resolves "who is this user?" from the token BEFORE
        // tenant context exists — the tenant middleware only runs after auth
        // succeeds, and derives the tenant FROM the resolved user. Since
        // TenantScope now fails closed instead of silently skipping, the
        // User lookups auth performs (retrieveById/retrieveByCredentials/
        // retrieveByToken) must explicitly bypass it, the same way
        // forgotPassword()/resetPassword() already do — otherwise every
        // authenticated request breaks on the very first user lookup.
        Auth::provider('tenant-aware-eloquent', function ($app, array $config) {
            return (new EloquentUserProvider($app['hash'], $config['model']))
                ->withQuery(fn ($query) => $query->withoutGlobalScope(TenantScope::class));
        });
    }

    /**
     * Define module schedules.
     *
     * @param  $schedule
     */
    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }
}
