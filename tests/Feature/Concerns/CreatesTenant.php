<?php

namespace Tests\Feature\Concerns;

use Modules\Auth\Models\User;
use Modules\Tenant\Models\Business;
use Modules\Tenant\Support\Tenant;

trait CreatesTenant
{
    /**
     * @return array{0: Business, 1: User}
     */
    protected function createBusinessWithOwner(array $businessAttrs = [], array $userAttrs = []): array
    {
        $business = Business::factory()->create($businessAttrs);

        app(Tenant::class)->set($business->id);

        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => User::ROLE_OWNER,
            ...$userAttrs,
        ]);

        return [$business, $user];
    }

    protected function actingAsTenantUser(User $user): static
    {
        app(Tenant::class)->set($user->business_id);

        return $this->actingAs($user, 'api');
    }

    /**
     * business_id is deliberately excluded from every tenant-scoped model's Fillable
     * list (it must never be settable by a client) — so it can't be passed to
     * ::create() directly. Instead, run the given callback with the tenant context
     * set to $business, letting BelongsToTenant's creating() hook assign it.
     */
    protected function asTenant(Business $business, \Closure $callback): mixed
    {
        $previous = app(Tenant::class)->id();
        app(Tenant::class)->set($business->id);

        try {
            return $callback();
        } finally {
            app(Tenant::class)->set($previous);
        }
    }
}
