<?php

namespace Modules\Appointments\Models;

use App\Casts\MoneyCast;
use App\Casts\SerializesMoneyAttributes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Auth\Models\User;
use Modules\Clients\Models\Client;
use Modules\Services\Models\Service;
use Modules\Tenant\Models\Concerns\BelongsToTenant;
use Modules\Tenant\Models\Concerns\HasBusinessCurrency;

#[Fillable(['client_id', 'service_id', 'user_id', 'starts_at', 'ends_at', 'status', 'notes', 'price', 'currency_code'])]
#[Hidden(['currency_code'])]
class Appointment extends Model
{
    use BelongsToTenant, HasBusinessCurrency, SerializesMoneyAttributes, SoftDeletes;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NO_SHOW = 'no_show';

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'price' => MoneyCast::class,
        ];
    }

    protected function moneyAttributes(): array
    {
        return ['price'];
    }

    // withTrashed() so a past appointment still shows who/what it was for even
    // after that client/service/staff member is later soft-deleted.
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }
}
