<?php

namespace Modules\Clients\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Appointments\Models\Appointment;
use Modules\Tenant\Models\Concerns\BelongsToTenant;

#[Fillable(['name', 'email', 'phone', 'notes'])]
class Client extends Model
{
    use BelongsToTenant;

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
