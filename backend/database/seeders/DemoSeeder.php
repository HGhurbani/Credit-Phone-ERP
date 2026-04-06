<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // Create Super Admin
        $superAdmin = User::firstOrCreate(
            ['email' => 'superadmin@creditphone.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'is_super_admin' => true,
                'is_active' => true,
                'locale' => 'ar',
            ]
        );
        if (!$superAdmin->hasRole('super_admin')) {
            $superAdmin->assignRole('super_admin');
        }

        // Create Credit Phone Tenant
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'credit-phone'],
            [
                'name' => 'Credit Phone',
                'email' => 'admin@creditphone.com',
                'phone' => '+966500000000',
                'currency' => 'QAR',
                'timezone' => 'Asia/Qatar',
                'locale' => 'ar',
                'status' => 'active',
                'settings' => [
                    'installment_admin_fee' => 0,
                    'default_durations' => [6, 12, 18, 24],
                    'invoice_prefix' => 'CP',
                ],
            ]
        );

        Setting::updateOrCreate(
            ['tenant_id' => $tenant->id, 'key' => 'installment_pricing_mode'],
            ['value' => 'percentage', 'group' => 'installment', 'type' => 'string']
        );
        Setting::updateOrCreate(
            ['tenant_id' => $tenant->id, 'key' => 'installment_monthly_percent_of_cash'],
            ['value' => '5', 'group' => 'installment', 'type' => 'string']
        );

        // Create Main Branch
        $mainBranch = Branch::firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'MAIN'],
            [
                'name' => 'Main Branch - الفرع الرئيسي',
                'code' => 'MAIN',
                'phone' => '+966500000001',
                'city' => 'Riyadh',
                'is_main' => true,
                'is_active' => true,
            ]
        );

        // Create Branch 2
        $branch2 = Branch::firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'JED'],
            [
                'name' => 'Jeddah Branch - فرع جدة',
                'code' => 'JED',
                'phone' => '+966500000002',
                'city' => 'Jeddah',
                'is_main' => false,
                'is_active' => true,
            ]
        );

        // Create Company Admin
        $admin = User::firstOrCreate(
            ['email' => 'admin@creditphone.com'],
            [
                'tenant_id' => $tenant->id,
                'branch_id' => $mainBranch->id,
                'name' => 'Company Admin',
                'password' => Hash::make('password'),
                'is_active' => true,
                'locale' => 'ar',
            ]
        );
        $admin->assignRole('company_admin');

        // Create Sales Agent
        $agent = User::firstOrCreate(
            ['email' => 'agent@creditphone.com'],
            [
                'tenant_id' => $tenant->id,
                'branch_id' => $mainBranch->id,
                'name' => 'Ahmed Sales Agent',
                'password' => Hash::make('password'),
                'is_active' => true,
                'locale' => 'ar',
            ]
        );
        $agent->assignRole('sales_agent');

        // Create Collector
        $collector = User::firstOrCreate(
            ['email' => 'collector@creditphone.com'],
            [
                'tenant_id' => $tenant->id,
                'branch_id' => $mainBranch->id,
                'name' => 'Mohammed Collector',
                'password' => Hash::make('password'),
                'is_active' => true,
                'locale' => 'ar',
            ]
        );
        $collector->assignRole('collector');

        // Create Categories
        $phones = Category::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Mobile Phones'],
            ['name_ar' => 'هواتف محمولة', 'is_active' => true]
        );

        $tablets = Category::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Tablets'],
            ['name_ar' => 'أجهزة لوحية', 'is_active' => true]
        );

        $laptops = Category::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Laptops'],
            ['name_ar' => 'لابتوب', 'is_active' => true]
        );

        // Create Brands
        $apple = Brand::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Apple'],
            ['is_active' => true]
        );

        $samsung = Brand::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Samsung'],
            ['is_active' => true]
        );

        $huawei = Brand::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Huawei'],
            ['is_active' => true]
        );

        // Create Products
        $products = [
            [
                'name' => 'iPhone 15 Pro Max',
                'name_ar' => 'آيفون 15 برو ماكس',
                'sku' => 'APPL-IP15PM',
                'category_id' => $phones->id,
                'brand_id' => $apple->id,
                'cash_price' => 5499,
                'installment_price' => 5999,
                'monthly_percent_of_cash' => 5,
                'min_down_payment' => 1000,
                'allowed_durations' => [6, 12, 18, 24],
                'is_active' => true,
            ],
            [
                'name' => 'Samsung Galaxy S24 Ultra',
                'name_ar' => 'سامسونج جالاكسي S24 الترا',
                'sku' => 'SAMS-S24U',
                'category_id' => $phones->id,
                'brand_id' => $samsung->id,
                'cash_price' => 4999,
                'installment_price' => 5499,
                'monthly_percent_of_cash' => 5,
                'min_down_payment' => 800,
                'allowed_durations' => [6, 12, 18, 24],
                'is_active' => true,
            ],
            [
                'name' => 'iPad Pro 12.9"',
                'name_ar' => 'آيباد برو 12.9 بوصة',
                'sku' => 'APPL-IPADPRO',
                'category_id' => $tablets->id,
                'brand_id' => $apple->id,
                'cash_price' => 4299,
                'installment_price' => 4799,
                'monthly_percent_of_cash' => 5,
                'min_down_payment' => 700,
                'allowed_durations' => [6, 12, 18, 24],
                'is_active' => true,
            ],
            [
                'name' => 'MacBook Pro 14"',
                'name_ar' => 'ماك بوك برو 14 بوصة',
                'sku' => 'APPL-MBP14',
                'category_id' => $laptops->id,
                'brand_id' => $apple->id,
                'cash_price' => 8999,
                'installment_price' => 9999,
                'monthly_percent_of_cash' => 5,
                'min_down_payment' => 1500,
                'allowed_durations' => [12, 18, 24],
                'is_active' => true,
            ],
        ];

        foreach ($products as $pData) {
            $product = Product::firstOrCreate(
                ['tenant_id' => $tenant->id, 'sku' => $pData['sku']],
                $pData
            );

            // Initialize stock for both branches
            foreach ([$mainBranch->id, $branch2->id] as $branchId) {
                Inventory::firstOrCreate(
                    ['product_id' => $product->id, 'branch_id' => $branchId],
                    ['quantity' => rand(10, 50), 'min_quantity' => 5]
                );
            }
        }

        $this->command->info('✅ Demo data seeded successfully!');
        $this->command->info('');
        $this->command->info('Login credentials:');
        $this->command->info('  Super Admin: superadmin@creditphone.com / password');
        $this->command->info('  Company Admin: admin@creditphone.com / password');
        $this->command->info('  Sales Agent: agent@creditphone.com / password');
        $this->command->info('  Collector: collector@creditphone.com / password');
    }
}
