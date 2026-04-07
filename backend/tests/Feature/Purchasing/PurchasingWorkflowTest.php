<?php

namespace Tests\Feature\Purchasing;

use App\Models\Branch;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchasingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_supplier_create_requires_permission(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->forTenant($tenant->id, $branch->id)->create();
        $user->assignRole('sales_agent');
        Sanctum::actingAs($user);

        $this->postJson('/api/suppliers', [
            'name' => 'Acme Wholesale',
        ])->assertForbidden();
    }

    public function test_supplier_create_succeeds_when_permitted(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $user = User::factory()->forTenant($tenant->id)->create();
        $user->assignRole('company_admin');
        Sanctum::actingAs($user);

        $this->postJson('/api/suppliers', [
            'name' => 'Acme Wholesale',
            'phone' => '123',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Acme Wholesale');
    }

    public function test_purchase_order_creation_and_totals(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->forTenant($tenant->id)->create();
        $user->assignRole('company_admin');
        Sanctum::actingAs($user);

        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'name' => 'Vendor',
            'is_active' => true,
        ]);
        $product = Product::factory()->forTenant($tenant->id)->create();

        $res = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'order_date' => now()->toDateString(),
            'status' => 'draft',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 100],
            ],
        ]);

        $res->assertCreated();
        $this->assertSame('200.00', $res->json('data.subtotal'));
        $this->assertSame('200.00', $res->json('data.total'));
    }

    public function test_goods_receipt_increases_inventory_and_logs_stock_movement(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->forTenant($tenant->id)->create();
        $user->assignRole('company_admin');
        Sanctum::actingAs($user);

        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'name' => 'V', 'is_active' => true]);
        $product = Product::factory()->forTenant($tenant->id)->create();

        $poRes = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'order_date' => now()->toDateString(),
            'status' => 'ordered',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 10],
            ],
        ]);
        $poRes->assertCreated();
        $poId = $poRes->json('data.id');
        $lineId = $poRes->json('data.items.0.id');

        $this->assertSame(0, Inventory::where('product_id', $product->id)->where('branch_id', $branch->id)->value('quantity') ?? 0);

        $recv = $this->postJson('/api/purchase-orders/'.$poId.'/receive', [
            'items' => [
                ['purchase_order_item_id' => $lineId, 'quantity' => 5],
            ],
        ]);
        $recv->assertCreated();

        $this->assertSame(5, (int) Inventory::where('product_id', $product->id)->where('branch_id', $branch->id)->value('quantity'));

        $this->assertSame(1, StockMovement::where('product_id', $product->id)->where('branch_id', $branch->id)->where('type', 'in')->count());
        $this->assertSame('received', PurchaseOrder::find($poId)->status);
    }

    public function test_partial_receipt_updates_status(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->forTenant($tenant->id)->create();
        $user->assignRole('company_admin');
        Sanctum::actingAs($user);

        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'name' => 'V', 'is_active' => true]);
        $product = Product::factory()->forTenant($tenant->id)->create();

        $poRes = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'order_date' => now()->toDateString(),
            'status' => 'ordered',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 1],
            ],
        ]);
        $poId = $poRes->json('data.id');
        $lineId = $poRes->json('data.items.0.id');

        $this->postJson('/api/purchase-orders/'.$poId.'/receive', [
            'items' => [
                ['purchase_order_item_id' => $lineId, 'quantity' => 3],
            ],
        ])->assertCreated();

        $this->assertSame('partially_received', PurchaseOrder::find($poId)->status);

        $this->postJson('/api/purchase-orders/'.$poId.'/receive', [
            'items' => [
                ['purchase_order_item_id' => $lineId, 'quantity' => 7],
            ],
        ])->assertCreated();

        $this->assertSame('received', PurchaseOrder::find($poId)->status);
    }

    public function test_over_receiving_is_rejected(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->forTenant($tenant->id)->create();
        $user->assignRole('company_admin');
        Sanctum::actingAs($user);

        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'name' => 'V', 'is_active' => true]);
        $product = Product::factory()->forTenant($tenant->id)->create();

        $poRes = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'order_date' => now()->toDateString(),
            'status' => 'ordered',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 1],
            ],
        ]);
        $poId = $poRes->json('data.id');
        $lineId = $poRes->json('data.items.0.id');

        $this->postJson('/api/purchase-orders/'.$poId.'/receive', [
            'items' => [
                ['purchase_order_item_id' => $lineId, 'quantity' => 3],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['items']);
    }

    public function test_branch_scoped_user_cannot_view_purchase_order_from_other_branch(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branchA = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $branchB = Branch::factory()->create(['tenant_id' => $tenant->id]);

        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'name' => 'V', 'is_active' => true]);
        $product = Product::factory()->forTenant($tenant->id)->create();

        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'branch_id' => $branchB->id,
            'purchase_number' => 'PO-TEST-000001',
            'status' => 'draft',
            'order_date' => now()->toDateString(),
            'subtotal' => 0,
            'discount_amount' => 0,
            'total' => 0,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_cost' => 1,
            'total' => 1,
        ]);

        $branchUser = User::factory()->forTenant($tenant->id, $branchA->id)->create();
        $branchUser->assignRole('branch_manager');
        Sanctum::actingAs($branchUser);

        $this->getJson('/api/purchase-orders/'.$po->id)->assertForbidden();
    }

    public function test_supplier_delete_blocked_when_purchase_orders_exist(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->forTenant($tenant->id)->create();
        $user->assignRole('company_admin');
        Sanctum::actingAs($user);

        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'name' => 'V', 'is_active' => true]);
        $product = Product::factory()->forTenant($tenant->id)->create();

        $poRes = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'order_date' => now()->toDateString(),
            'status' => 'draft',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1],
            ],
        ]);
        $poRes->assertCreated();

        $this->deleteJson('/api/suppliers/'.$supplier->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['supplier']);
    }
}
