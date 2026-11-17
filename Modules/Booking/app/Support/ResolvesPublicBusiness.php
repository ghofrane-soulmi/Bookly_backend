<?php

namespace Modules\Booking\Support;

use Modules\Tenant\Models\Business;
use Modules\Tenant\Support\Tenant;

trait ResolvesPublicBusiness
{
    /**
     * Look up an active business by its public slug and point the tenant
     * context at it, so tenant-scoped models (Service, User, Appointment...)
     * only ever see this business's rows for the rest of the request.
     */
    protected function resolveBusiness(string $slug): ?Business
    {
        $business = Business::where('slug', $slug)->where('is_active', true)->first();

        if ($business) {
            app(Tenant::class)->set($business->id);
        }

        return $business;
    }
}
