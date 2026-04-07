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
            // Tenants (platform-level; typically super_admin only)
            'tenants.view', 'tenants.create', 'tenants.update', 'tenants.delete',

            // Dashboard
            'dashboard.view',

            // Branches
            'branches.view', 'branches.create', 'branches.update', 'branches.delete',

            // Users
            'users.view', 'users.create', 'users.update', 'users.delete',

            // Roles (listing assignable roles)
            'roles.view',

            // Customers
            'customers.view', 'customers.create', 'customers.update', 'customers.delete',
            'customers.documents.upload',

            // Products
            'products.view', 'products.create', 'products.update', 'products.delete',
            'products.stock.adjust',

            // Categories & brands (catalog metadata)
            'categories.view', 'categories.create', 'categories.update', 'categories.delete',
            'brands.view', 'brands.create', 'brands.update', 'brands.delete',

            // Orders
            'orders.view', 'orders.create', 'orders.approve', 'orders.reject', 'orders.delete',

            // Contracts
            'contracts.view', 'contracts.create', 'contracts.update',

            // Payments
            'payments.view', 'payments.create', 'payments.collections',

            // Invoices
            'invoices.view', 'invoices.record_payment', 'invoices.update',

            // Reports
            'reports.view', 'reports.export',

            // Settings
            'settings.view', 'settings.update',

            // Suppliers & purchasing
            'suppliers.view', 'suppliers.create', 'suppliers.update', 'suppliers.delete',
            'purchases.view', 'purchases.create', 'purchases.update', 'purchases.delete',
            'purchases.receive', 'purchases.update_status',

            // Cash & expenses (operational)
            'cashboxes.view', 'cashboxes.manage',
            'cash_transactions.view',
            'expenses.view', 'expenses.create', 'expenses.update', 'expenses.delete',

            // Collections — customer statement & follow-up
            'customer_statement.view',
            'collections.followup.view', 'collections.followup.create',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        // Super Admin — all permissions
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
            'invoices.view', 'invoices.record_payment', 'invoices.update',
            'reports.view', 'reports.export',
            'settings.view', 'settings.update',
            'dashboard.view',
            'categories.view', 'categories.create', 'categories.update', 'categories.delete',
            'brands.view', 'brands.create', 'brands.update', 'brands.delete',
            'roles.view',
            'suppliers.view', 'suppliers.create', 'suppliers.update', 'suppliers.delete',
            'purchases.view', 'purchases.create', 'purchases.update', 'purchases.delete',
            'purchases.receive', 'purchases.update_status',
            'cashboxes.view', 'cashboxes.manage', 'cash_transactions.view',
            'expenses.view', 'expenses.create', 'expenses.update', 'expenses.delete',
            'customer_statement.view', 'collections.followup.view', 'collections.followup.create',
        ]);

        // Branch Manager
        $branchManager = Role::firstOrCreate(['name' => 'branch_manager', 'guard_name' => 'web']);
        $branchManager->syncPermissions([
            'dashboard.view',
            'branches.view',
            'users.view',
            'customers.view', 'customers.create', 'customers.update', 'customers.documents.upload',
            'products.view', 'products.stock.adjust',
            'categories.view', 'brands.view',
            'orders.view', 'orders.create', 'orders.approve', 'orders.reject',
            'contracts.view', 'contracts.create',
            'payments.view', 'payments.create', 'payments.collections',
            'invoices.view', 'invoices.record_payment', 'invoices.update',
            'reports.view',
            'suppliers.view', 'suppliers.create', 'suppliers.update', 'suppliers.delete',
            'purchases.view', 'purchases.create', 'purchases.update', 'purchases.delete',
            'purchases.receive', 'purchases.update_status',
            'cashboxes.view', 'cashboxes.manage', 'cash_transactions.view',
            'expenses.view', 'expenses.create', 'expenses.update', 'expenses.delete',
            'customer_statement.view', 'collections.followup.view', 'collections.followup.create',
        ]);

        // Sales Agent
        $salesAgent = Role::firstOrCreate(['name' => 'sales_agent', 'guard_name' => 'web']);
        $salesAgent->syncPermissions([
            'dashboard.view',
            'customers.view', 'customers.create', 'customers.update', 'customers.documents.upload',
            'products.view',
            'categories.view', 'brands.view',
            'orders.view', 'orders.create',
        ]);

        // Collector
        $collector = Role::firstOrCreate(['name' => 'collector', 'guard_name' => 'web']);
        $collector->syncPermissions([
            'dashboard.view',
            'customers.view',
            'contracts.view',
            'payments.view', 'payments.create', 'payments.collections',
            'invoices.view',
            'categories.view', 'brands.view',
            'cashboxes.view',
            'customer_statement.view',
            'collections.followup.view', 'collections.followup.create',
        ]);

        // Accountant
        $accountant = Role::firstOrCreate(['name' => 'accountant', 'guard_name' => 'web']);
        $accountant->syncPermissions([
            'dashboard.view',
            'customers.view',
            'orders.view',
            'contracts.view',
            'payments.view', 'payments.collections',
            'invoices.view', 'invoices.record_payment', 'invoices.update',
            'reports.view', 'reports.export',
            'categories.view', 'brands.view',
            'suppliers.view',
            'purchases.view',
            'cashboxes.view', 'cash_transactions.view',
            'expenses.view',
            'customer_statement.view', 'collections.followup.view',
        ]);
    }
}
