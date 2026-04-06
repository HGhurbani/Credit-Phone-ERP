<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            // Tenants
            'tenants.view', 'tenants.create', 'tenants.update', 'tenants.delete',

            // Branches
            'branches.view', 'branches.create', 'branches.update', 'branches.delete',

            // Users
            'users.view', 'users.create', 'users.update', 'users.delete',

            // Customers
            'customers.view', 'customers.create', 'customers.update', 'customers.delete',
            'customers.documents.upload',

            // Products
            'products.view', 'products.create', 'products.update', 'products.delete',
            'products.stock.adjust',

            // Orders
            'orders.view', 'orders.create', 'orders.approve', 'orders.reject', 'orders.delete',

            // Contracts
            'contracts.view', 'contracts.create', 'contracts.update',

            // Payments
            'payments.view', 'payments.create', 'payments.collections',

            // Invoices
            'invoices.view',

            // Reports
            'reports.view', 'reports.export',

            // Settings
            'settings.view', 'settings.update',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        // Super Admin - all permissions
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin->syncPermissions(Permission::all());

        // Company Admin
        $companyAdmin = Role::firstOrCreate(['name' => 'company_admin', 'guard_name' => 'web']);
        $companyAdmin->syncPermissions([
            'branches.view', 'branches.create', 'branches.update', 'branches.delete',
            'users.view', 'users.create', 'users.update', 'users.delete',
            'customers.view', 'customers.create', 'customers.update', 'customers.delete', 'customers.documents.upload',
            'products.view', 'products.create', 'products.update', 'products.delete', 'products.stock.adjust',
            'orders.view', 'orders.create', 'orders.approve', 'orders.reject', 'orders.delete',
            'contracts.view', 'contracts.create', 'contracts.update',
            'payments.view', 'payments.create', 'payments.collections',
            'invoices.view',
            'reports.view', 'reports.export',
            'settings.view', 'settings.update',
        ]);

        // Branch Manager
        $branchManager = Role::firstOrCreate(['name' => 'branch_manager', 'guard_name' => 'web']);
        $branchManager->syncPermissions([
            'branches.view',
            'users.view',
            'customers.view', 'customers.create', 'customers.update', 'customers.documents.upload',
            'products.view', 'products.stock.adjust',
            'orders.view', 'orders.create', 'orders.approve', 'orders.reject',
            'contracts.view', 'contracts.create',
            'payments.view', 'payments.create', 'payments.collections',
            'invoices.view',
            'reports.view',
        ]);

        // Sales Agent
        $salesAgent = Role::firstOrCreate(['name' => 'sales_agent', 'guard_name' => 'web']);
        $salesAgent->syncPermissions([
            'customers.view', 'customers.create', 'customers.update', 'customers.documents.upload',
            'products.view',
            'orders.view', 'orders.create',
        ]);

        // Collector
        $collector = Role::firstOrCreate(['name' => 'collector', 'guard_name' => 'web']);
        $collector->syncPermissions([
            'customers.view',
            'contracts.view',
            'payments.view', 'payments.create', 'payments.collections',
            'invoices.view',
        ]);

        // Accountant
        $accountant = Role::firstOrCreate(['name' => 'accountant', 'guard_name' => 'web']);
        $accountant->syncPermissions([
            'customers.view',
            'orders.view',
            'contracts.view',
            'payments.view', 'payments.collections',
            'invoices.view',
            'reports.view', 'reports.export',
        ]);
    }
}
