<?php

namespace Modules\Appointments\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class AppointmentsServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Appointments';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'appointments';

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];
}
