<?php

namespace Modules\Appointments\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Appointments\Console\Commands\SendAppointmentReminders;
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
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        SendAppointmentReminders::class,
    ];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    /**
     * Define module schedules.
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command(SendAppointmentReminders::class)->hourly();
    }
}
