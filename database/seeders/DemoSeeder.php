<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Appointments\Models\Appointment;
use Modules\Auth\Models\User;
use Modules\Auth\Support\DefaultRoles;
use Modules\Booking\Models\Customer;
use Modules\Clients\Models\Client;
use Modules\Services\Models\Service;
use Modules\Tenant\Models\Business;
use Modules\Tenant\Support\Tenant;
use Spatie\Permission\PermissionRegistrar;

/**
 * Portfolio demo data: one salon with staff, services, opening hours, a
 * customer account and a few appointments. Safe to run more than once —
 * it does nothing if the demo business already exists.
 *
 *   php artisan db:seed --class=DemoSeeder
 *
 * Logins (password for all: "123456789"):
 *   back office:  owner@demo.bookly.test, sami@demo.bookly.test (staff)
 *   front office: customer@demo.bookly.test
 */
class DemoSeeder extends Seeder
{
    public const SLUG = 'demo-salon';

    public const PASSWORD = '123456789';

    public function run(): void
    {
        if (Business::where('slug', self::SLUG)->exists()) {
            $this->command?->info('Demo business already exists — skipping.');

            return;
        }

        DB::transaction(function () {
            $weekday = ['open' => '09:00', 'close' => '18:00'];

            $business = Business::create([
                'name' => 'Demo Salon',
                'slug' => self::SLUG,
                'email' => 'hello@demo.bookly.test',
                'phone' => '+216 71 000 000',
                'timezone' => 'Africa/Tunis',
                'currency_code' => 'TND',
                'locale' => 'en',
                'operating_hours' => [
                    'monday' => $weekday,
                    'tuesday' => $weekday,
                    'wednesday' => $weekday,
                    'thursday' => $weekday,
                    'friday' => $weekday,
                    'saturday' => ['open' => '09:00', 'close' => '14:00'],
                    'sunday' => ['open' => null, 'close' => null],
                ],
            ]);

            app(Tenant::class)->set($business->id);
            app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);
            DefaultRoles::seed($business);

            $staff = [];
            foreach ([
                ['Amira Ben Ali', 'owner@demo.bookly.test', User::ROLE_OWNER],
                ['Sami Trabelsi', 'sami@demo.bookly.test', User::ROLE_STAFF],
                ['Leila Haddad', 'leila@demo.bookly.test', User::ROLE_STAFF],
            ] as [$name, $email, $role]) {
                $user = new User(['name' => $name, 'email' => $email, 'password' => self::PASSWORD]);
                $user->business_id = $business->id;
                $user->save();
                $user->assignRole($role);
                $staff[] = $user;
            }

            $services = collect([
                ['Haircut & styling', 'Hair', 45, 35, '#5B5BD6', 'Wash, cut and blow-dry, tailored to your face shape.'],
                ['Beard trim', 'Hair', 20, 15, '#3B82C4', 'Shape-up and hot-towel finish.'],
                ['Hair colouring', 'Hair', 90, 90, '#D9822B', 'Full colour with a gloss treatment.'],
                ['Classic manicure', 'Nails', 30, 25, '#D93F4C', 'Shape, cuticle care and polish.'],
                ['Relaxing facial', 'Skin care', 60, 70, '#2E9E6B', 'Deep cleanse, mask and massage.'],
            ])->map(fn ($s) => Service::create([
                'name' => $s[0], 'category' => $s[1], 'duration_minutes' => $s[2],
                'price' => $s[3] * 1000, 'color' => $s[4], 'description' => $s[5], 'is_active' => true,
            ]));

            $customer = Customer::create([
                'name' => 'Yasmine Customer',
                'email' => 'customer@demo.bookly.test',
                'phone' => '+216 20 000 000',
                'password' => self::PASSWORD,
            ]);

            $client = new Client(['name' => $customer->name, 'email' => $customer->email, 'phone' => $customer->phone]);
            $client->customer_id = $customer->id;
            $client->save();

            $walkIn = Client::create(['name' => 'Karim Walk-in', 'phone' => '+216 22 111 222']);

            // A past visit and two upcoming ones, at 10:00/14:00 Tunis time on
            // weekdays so they fall inside the opening hours.
            $tz = $business->timezone;
            $nextWeekday = function (int $daysAhead, string $time) use ($tz) {
                $day = CarbonImmutable::now($tz)->addDays($daysAhead);
                while ($day->isWeekend()) {
                    $day = $day->addDay();
                }

                return $day->setTimeFromTimeString($time)->utc();
            };

            foreach ([
                [$client, $services[0], $staff[1], $nextWeekday(-14, '10:00'), Appointment::STATUS_COMPLETED],
                [$client, $services[4], $staff[2], $nextWeekday(3, '14:00'), Appointment::STATUS_SCHEDULED],
                [$walkIn, $services[1], $staff[1], $nextWeekday(1, '11:00'), Appointment::STATUS_SCHEDULED],
            ] as [$who, $service, $member, $start, $status]) {
                Appointment::create([
                    'client_id' => $who->id,
                    'service_id' => $service->id,
                    'user_id' => $member->id,
                    'starts_at' => $start,
                    'ends_at' => $start->addMinutes($service->duration_minutes),
                    'status' => $status,
                    'price' => $service->price,
                ]);
            }
        });

        $this->command?->info('Demo data created: /b/'.self::SLUG);
    }
}
