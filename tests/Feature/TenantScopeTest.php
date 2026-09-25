<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Clients\Models\Client;
use Modules\Services\Models\Service;
use Modules\Tenant\Models\Scopes\TenantScope;
use Modules\Tenant\Support\NoTenantContextException;
use Modules\Tenant\Support\Tenant;
use Tests\Feature\Concerns\CreatesTenant;
use Tests\TestCase;

class TenantScopeTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_querying_a_tenant_scoped_model_with_no_tenant_context_throws(): void
    {
        app(Tenant::class)->set(null);

        $this->expectException(NoTenantContextException::class);

        Service::count();
    }

    public function test_querying_a_different_tenant_scoped_model_with_no_tenant_context_also_throws(): void
    {
        app(Tenant::class)->set(null);

        $this->expectException(NoTenantContextException::class);

        Client::all();
    }

    public function test_bypassing_the_scope_explicitly_does_not_throw_even_with_no_tenant_context(): void
    {
        [$business] = $this->createBusinessWithOwner();

        $this->asTenant($business, fn () => Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 20]));

        app(Tenant::class)->set(null);

        $count = Service::withoutGlobalScope(TenantScope::class)->count();

        $this->assertSame(1, $count);
    }

    public function test_scoped_query_still_works_normally_with_tenant_context_set(): void
    {
        [$business, $user] = $this->createBusinessWithOwner();

        $this->asTenant($business, fn () => Service::create(['name' => 'Cut', 'duration_minutes' => 30, 'price' => 20]));

        $this->actingAsTenantUser($user);

        $this->assertSame(1, Service::count());
    }
}
