<?php

namespace Tests;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\Concerns\BuildsInstallmentScenarios;

abstract class TestCase extends BaseTestCase
{
    use BuildsInstallmentScenarios;

    protected function seedPermissions(): void
    {
        $this->seed(RolePermissionSeeder::class);
    }
}
