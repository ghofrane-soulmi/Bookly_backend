<?php

namespace Modules\Tenant\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Platform\Models\Currency;
use Modules\Tenant\Database\Factories\BusinessFactory;

#[Fillable(['name', 'slug', 'email', 'phone', 'timezone', 'currency_code', 'locale', 'is_active', 'notify_confirmation_email', 'notify_reminder_email', 'reminder_lead_hours', 'operating_hours'])]
class Business extends Model
{
    use HasFactory;

    protected static function newFactory(): BusinessFactory
    {
        return BusinessFactory::new();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'notify_confirmation_email' => 'boolean',
            'notify_reminder_email' => 'boolean',
            'reminder_lead_hours' => 'integer',
            'operating_hours' => 'array',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public static function uniqueSlugFrom(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while (static::where('slug', $slug)->exists()) {
            $slug = "{$base}-".++$suffix;
        }

        return $slug;
    }
}
