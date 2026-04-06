<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->name().' Branch',
            'code' => fake()->unique()->bothify('B###??'),
            'is_main' => false,
            'is_active' => true,
        ];
    }
}
