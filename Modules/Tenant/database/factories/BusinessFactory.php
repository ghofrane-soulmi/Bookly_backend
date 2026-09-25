<?php

namespace Modules\Tenant\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Tenant\Models\Business;

/**
 * @extends Factory<Business>
 */
#[UseModel(Business::class)]
class BusinessFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Business::uniqueSlugFrom($name),
            'timezone' => 'UTC',
            'currency_code' => 'USD',
            'is_active' => true,
        ];
    }
}
