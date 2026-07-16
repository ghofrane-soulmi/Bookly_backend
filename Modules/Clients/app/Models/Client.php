<?php

namespace Modules\Clients\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Modules\Tenant\Models\Concerns\BelongsToTenant;

#[Fillable(['name', 'email', 'phone', 'notes'])]
class Client extends Model
{
    use BelongsToTenant;
}
