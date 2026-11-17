<?php

namespace Modules\Booking\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Modules\Clients\Models\Client;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * A front-office (end customer) account. Deliberately NOT tenant-scoped.
 * Authenticates through the separate `customer` JWT guard; jwt.lock_subject
 * stamps the model class into each token, so a customer token is rejected by
 * the staff `api` guard and vice versa.
 */
#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password'])]
class Customer extends Authenticatable implements JWTSubject
{
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    /**
     * The customer's client record at every business they've booked with.
     * Client is tenant-scoped, so the scope is removed here on purpose.
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class)->withoutGlobalScopes();
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    public function getJWTCustomClaims(): array
    {
        return [];
    }
}
