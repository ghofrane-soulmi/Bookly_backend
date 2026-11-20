<?php

namespace Tests\Feature;

use Database\Seeders\DemoInvoicesSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Booking\Models\Customer;
use Modules\Tenant\Models\Business;
use Modules\Tenant\Models\Scopes\TenantScope;
use Modules\Tenant\Support\Tenant;
use Tests\Feature\Concerns\CreatesTenant;
use Tests\TestCase;

class DemoTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    protected function seedDemo(): Business
    {
        $this->seed([DemoSeeder::class, DemoInvoicesSeeder::class]);
        app(Tenant::class)->set(null);

        return Business::where('slug', DemoSeeder::SLUG)->firstOrFail();
    }

    protected function demoCounts(): array
    {
        $id = Business::where('slug', DemoSeeder::SLUG)->value('id');

        return [
            'users' => DB::table('users')->where('business_id', $id)->count(),
            'services' => DB::table('services')->where('business_id', $id)->count(),
            'clients' => DB::table('clients')->where('business_id', $id)->count(),
            'appointments' => DB::table('appointments')->where('business_id', $id)->count(),
            'invoices' => DB::table('invoices')->where('business_id', $id)->count(),
        ];
    }

    public function test_reset_rebuilds_the_demo_and_undoes_visitor_changes(): void
    {
        $demo = $this->seedDemo();
        $expected = $this->demoCounts();

        // A visitor renames the salon, deletes the services and adds a client.
        $demo->update(['name' => 'Hacked salon']);
        DB::table('appointments')->where('business_id', $demo->id)->delete();
        DB::table('clients')->insert(['business_id' => $demo->id, 'name' => 'Spam', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('demo:reset')->assertSuccessful();

        $fresh = Business::where('slug', DemoSeeder::SLUG)->firstOrFail();
        $this->assertSame('Demo Salon', $fresh->name);
        $this->assertSame($expected, $this->demoCounts());
        $this->assertSame(1, Customer::where('email', 'customer@demo.bookly.test')->count());

        // The demo owner can still log in with the published password.
        $this->postJson('/api/auth/login', ['email' => 'owner@demo.bookly.test', 'password' => DemoSeeder::PASSWORD])
            ->assertOk();
    }

    public function test_reset_never_touches_other_businesses(): void
    {
        $this->seedDemo();
        [$other, $owner] = $this->createBusinessWithOwner();
        app(Tenant::class)->set(null);

        $this->artisan('demo:reset')->assertSuccessful();
        $this->artisan('demo:reset')->assertSuccessful();

        $this->assertTrue(Business::whereKey($other->id)->exists());
        $this->assertTrue(User::withoutGlobalScope(TenantScope::class)->whereKey($owner->id)->exists());
        $this->assertSame(1, Business::where('slug', DemoSeeder::SLUG)->count());
    }

    public function test_reset_cron_endpoint_requires_the_secret(): void
    {
        config(['services.cron.secret' => 'top-secret']);

        $this->getJson('/api/cron/reset-demo')->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer nope')->getJson('/api/cron/reset-demo')->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer top-secret')->getJson('/api/cron/reset-demo')
            ->assertOk()
            ->assertJsonPath('status.message', 'Demo reset');

        $this->assertTrue(Business::where('slug', DemoSeeder::SLUG)->exists());
    }

    public function test_demo_staff_cannot_change_their_login_but_can_edit_their_name(): void
    {
        $this->seedDemo();
        $token = $this->postJson('/api/auth/login', ['email' => 'owner@demo.bookly.test', 'password' => DemoSeeder::PASSWORD])
            ->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/profile', ['password' => 'newpassword1', 'current_password' => DemoSeeder::PASSWORD])
            ->assertStatus(403);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/profile', ['email' => 'mine@example.test'])
            ->assertStatus(403);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/profile', ['name' => 'Visitor', 'email' => 'owner@demo.bookly.test'])
            ->assertJsonPath('status.code', 200);
    }

    public function test_demo_owner_cannot_change_another_demo_staff_login(): void
    {
        $this->seedDemo();
        $token = $this->postJson('/api/auth/login', ['email' => 'owner@demo.bookly.test', 'password' => DemoSeeder::PASSWORD])
            ->json('token');
        $staffId = User::withoutGlobalScope(TenantScope::class)->where('email', 'flen@demo.bookly.test')->value('id');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/staff/update/{$staffId}", ['password' => 'newpassword1'])
            ->assertStatus(403);
    }

    public function test_demo_customer_cannot_change_their_login(): void
    {
        $this->seedDemo();
        $token = $this->postJson('/api/customer/auth/login', ['email' => 'customer@demo.bookly.test', 'password' => DemoSeeder::PASSWORD])
            ->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/customer/me', [
                'password' => 'newpassword1', 'password_confirmation' => 'newpassword1',
                'current_password' => DemoSeeder::PASSWORD,
            ])
            ->assertStatus(403);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/customer/me', ['phone' => '+216 99 999 999'])
            ->assertOk();
    }

    public function test_demo_accounts_cannot_be_taken_over_with_a_password_reset(): void
    {
        $this->seedDemo();

        $this->postJson('/api/auth/reset-password', [
            'email' => 'owner@demo.bookly.test', 'token' => 'x', 'password' => 'newpassword1', 'password_confirmation' => 'newpassword1',
        ])->assertStatus(403);

        $this->postJson('/api/customer/auth/reset-password', [
            'email' => 'customer@demo.bookly.test', 'token' => 'x', 'password' => 'newpassword1', 'password_confirmation' => 'newpassword1',
        ])->assertStatus(403);
    }
}
