<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\Branch;
use App\Models\CashTransaction;
use App\Models\Cashbox;
use App\Models\Customer;
use App\Models\DocumentPosting;
use App\Models\Expense;
use App\Models\GoodsReceipt;
use App\Models\InstallmentContract;
use App\Models\Inventory;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AutomaticDocumentPostingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPermissions();
    }

    public function test_cash_order_approval_creates_invoice_and_posts_accounting_entry(): void
    {
        [$tenant, $branch, $admin] = $this->createAdminContext();
        $customer = Customer::factory()->forTenantBranch($tenant->id, $branch->id)->create();
        $product = Product::factory()->forTenant($tenant->id)->create([
            'name' => 'Phone X',
            'cash_price' => 1500,
            'installment_price' => 1800,
            'cost_price' => 900,
        ]);
        Inventory::create([
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'quantity' => 5,
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sales_agent_id' => $admin->id,
            'order_number' => 'ORD-TST-001',
            'type' => 'cash',
            'status' => 'draft',
            'subtotal' => 1500,
            'discount_amount' => 0,
            'total' => 1500,
        ]);

        OrderItem::factory()->forOrderProduct($order->id, $product->id, $product->name, 1500, 1)->create([
            'product_sku' => 'SKU-CASH-1',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/orders/'.$order->id.'/approve')->assertOk();

        $invoice = Invoice::query()->where('order_id', $order->id)->firstOrFail();
        $entry = JournalEntry::query()
            ->where('source_type', Invoice::class)
            ->where('source_id', $invoice->id)
            ->where('event', 'cash_sale_invoice')
            ->firstOrFail();

        $this->assertEquals('posted', $entry->status);
        $this->assertEquals(1500.0, $this->lineAmount($entry, 'accounts_receivable_trade', 'debit'));
        $this->assertEquals(1500.0, $this->lineAmount($entry, 'sales_revenue_cash', 'credit'));
        $this->assertEquals(900.0, $this->lineAmount($entry, 'cost_of_goods_sold', 'debit'));
        $this->assertEquals(900.0, $this->lineAmount($entry, 'inventory', 'credit'));
        $this->assertDatabaseHas('document_postings', [
            'tenant_id' => $tenant->id,
            'source_type' => Invoice::class,
            'source_id' => $invoice->id,
            'event' => 'cash_sale_invoice',
        ]);
        $this->assertSame(4, Inventory::query()->where('product_id', $product->id)->where('branch_id', $branch->id)->value('quantity'));
    }

    public function test_invoice_cancellation_reverses_posting_and_restores_stock(): void
    {
        [$tenant, $branch, $admin] = $this->createAdminContext();
        $customer = Customer::factory()->forTenantBranch($tenant->id, $branch->id)->create();
        $product = Product::factory()->forTenant($tenant->id)->create([
            'cash_price' => 1000,
            'cost_price' => 650,
        ]);
        Inventory::create([
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'quantity' => 3,
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sales_agent_id' => $admin->id,
            'order_number' => 'ORD-TST-002',
            'type' => 'cash',
            'status' => 'draft',
            'subtotal' => 1000,
            'discount_amount' => 0,
            'total' => 1000,
        ]);
        OrderItem::factory()->forOrderProduct($order->id, $product->id, $product->name, 1000, 1)->create();

        Sanctum::actingAs($admin);
        $this->postJson('/api/orders/'.$order->id.'/approve')->assertOk();

        $invoice = Invoice::query()->where('order_id', $order->id)->firstOrFail();

        $this->patchJson('/api/invoices/'.$invoice->id, ['status' => 'cancelled'])->assertOk();

        $posting = DocumentPosting::query()
            ->where('source_type', Invoice::class)
            ->where('source_id', $invoice->id)
            ->where('event', 'cash_sale_invoice')
            ->firstOrFail();
        $originalEntry = $posting->journalEntry()->firstOrFail();
        $reversalEntry = $posting->reversalEntry()->firstOrFail();

        $this->assertEquals('reversed', $originalEntry->fresh()->status);
        $this->assertNotNull($posting->reversal_entry_id);
        $this->assertEquals(1000.0, $this->lineAmount($reversalEntry, 'sales_revenue_cash', 'debit'));
        $this->assertEquals(1000.0, $this->lineAmount($reversalEntry, 'accounts_receivable_trade', 'credit'));
        $this->assertSame(3, Inventory::query()->where('product_id', $product->id)->where('branch_id', $branch->id)->value('quantity'));
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_contract_creation_and_payment_create_accounting_entries(): void
    {
        $ctx = $this->createApprovedInstallmentOrderWithInventory();
        $ctx['product']->update(['cost_price' => 600]);

        $admin = User::factory()->forTenant($ctx['tenant']->id)->create();
        $admin->assignRole('company_admin');
        Sanctum::actingAs($admin);

        $this->postJson('/api/contracts', $this->validContractPayloadForOrder($ctx['order']))
            ->assertCreated();

        $contract = InstallmentContract::query()->where('order_id', $ctx['order']->id)->firstOrFail();
        $contractEntry = JournalEntry::query()
            ->where('source_type', InstallmentContract::class)
            ->where('source_id', $contract->id)
            ->where('event', 'installment_contract')
            ->firstOrFail();

        $this->assertEquals((float) $contract->financed_amount, $this->lineAmount($contractEntry, 'accounts_receivable_installment', 'debit'));
        $this->assertEquals((float) $contract->financed_amount, $this->lineAmount($contractEntry, 'sales_revenue_installment', 'credit'));
        $this->assertEquals(600.0, $this->lineAmount($contractEntry, 'cost_of_goods_sold', 'debit'));
        $this->assertEquals(600.0, $this->lineAmount($contractEntry, 'inventory', 'credit'));

        $this->postJson('/api/payments', [
            'contract_id' => $contract->id,
            'amount' => 75,
            'payment_method' => 'cash',
            'payment_date' => now()->toDateString(),
        ])->assertCreated();

        $payment = Payment::query()->where('contract_id', $contract->id)->latest('id')->firstOrFail();
        $paymentEntry = JournalEntry::query()
            ->where('source_type', Payment::class)
            ->where('source_id', $payment->id)
            ->where('event', 'contract_payment')
            ->firstOrFail();

        $this->assertEquals(75.0, $this->lineAmount($paymentEntry, 'cash_on_hand', 'debit'));
        $this->assertEquals(75.0, $this->lineAmount($paymentEntry, 'accounts_receivable_installment', 'credit'));
    }

    public function test_expense_create_and_cancel_post_and_reverse_accounting(): void
    {
        [$tenant, $branch, $admin] = $this->createAdminContext();
        $cashbox = Cashbox::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => 'Main',
            'opening_balance' => 1000,
            'current_balance' => 1000,
            'is_active' => true,
            'is_primary' => true,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/expenses', [
            'branch_id' => $branch->id,
            'cashbox_id' => $cashbox->id,
            'category' => 'Rent',
            'amount' => 200,
            'expense_date' => now()->toDateString(),
        ])->assertCreated();

        $expense = Expense::query()->latest('id')->firstOrFail();
        $entry = JournalEntry::query()
            ->where('source_type', Expense::class)
            ->where('source_id', $expense->id)
            ->where('event', 'expense')
            ->firstOrFail();

        $this->assertEquals(200.0, $this->lineAmount($entry, 'general_expense', 'debit'));
        $this->assertEquals(200.0, $this->lineAmount($entry, 'cash_on_hand', 'credit'));

        $this->postJson('/api/expenses/'.$expense->id.'/cancel')->assertOk();

        $posting = DocumentPosting::query()
            ->where('source_type', Expense::class)
            ->where('source_id', $expense->id)
            ->where('event', 'expense')
            ->firstOrFail();
        $reversal = $posting->reversalEntry()->firstOrFail();

        $this->assertEquals(200.0, $this->lineAmount($reversal, 'cash_on_hand', 'debit'));
        $this->assertEquals(200.0, $this->lineAmount($reversal, 'general_expense', 'credit'));
        $this->assertSame('1000.00', $cashbox->fresh()->current_balance);
    }

    public function test_goods_receipt_posts_inventory_entry(): void
    {
        [$tenant, $branch, $admin] = $this->createAdminContext();
        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'name' => 'Supplier A',
            'is_active' => true,
        ]);
        $product = Product::factory()->forTenant($tenant->id)->create();

        $purchaseOrder = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'branch_id' => $branch->id,
            'purchase_number' => 'PO-TST-001',
            'status' => 'ordered',
            'order_date' => now()->toDateString(),
            'subtotal' => 300,
            'discount_amount' => 0,
            'total' => 300,
            'created_by' => $admin->id,
        ]);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $purchaseOrder->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'unit_cost' => 100,
            'total' => 300,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/purchase-orders/'.$purchaseOrder->id.'/receive', [
            'items' => [
                ['purchase_order_item_id' => $item->id, 'quantity' => 2],
            ],
        ])->assertCreated();

        $receipt = GoodsReceipt::query()->latest('id')->firstOrFail();
        $entry = JournalEntry::query()
            ->where('source_type', GoodsReceipt::class)
            ->where('source_id', $receipt->id)
            ->where('event', 'goods_receipt')
            ->firstOrFail();

        $this->assertEquals(200.0, $this->lineAmount($entry, 'inventory', 'debit'));
        $this->assertEquals(200.0, $this->lineAmount($entry, 'goods_received_not_billed', 'credit'));
        $this->assertSame(2, Inventory::query()->where('product_id', $product->id)->where('branch_id', $branch->id)->value('quantity'));
    }

    public function test_manual_cash_transaction_posts_accounting_entry(): void
    {
        [$tenant, $branch, $admin] = $this->createAdminContext();
        $cashbox = Cashbox::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => 'Main',
            'opening_balance' => 100,
            'current_balance' => 100,
            'is_active' => true,
            'is_primary' => true,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/cashboxes/'.$cashbox->id.'/transactions', [
            'transaction_type' => CashTransaction::TYPE_OTHER_IN,
            'amount' => 25,
            'transaction_date' => now()->toDateString(),
        ])->assertCreated();

        $transaction = CashTransaction::query()->latest('id')->firstOrFail();
        $entry = JournalEntry::query()
            ->where('source_type', CashTransaction::class)
            ->where('source_id', $transaction->id)
            ->where('event', 'manual_cash_transaction')
            ->firstOrFail();

        $this->assertEquals(25.0, $this->lineAmount($entry, 'cash_on_hand', 'debit'));
        $this->assertEquals(25.0, $this->lineAmount($entry, 'other_income', 'credit'));
    }

    public function test_journal_entries_endpoint_returns_posted_entries_with_lines(): void
    {
        [$tenant, $branch, $admin] = $this->createAdminContext();
        $customer = Customer::factory()->forTenantBranch($tenant->id, $branch->id)->create();
        $product = Product::factory()->forTenant($tenant->id)->create([
            'cash_price' => 700,
            'cost_price' => 400,
        ]);
        Inventory::create([
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'quantity' => 3,
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sales_agent_id' => $admin->id,
            'order_number' => 'ORD-TST-003',
            'type' => 'cash',
            'status' => 'draft',
            'subtotal' => 700,
            'discount_amount' => 0,
            'total' => 700,
        ]);
        OrderItem::factory()->forOrderProduct($order->id, $product->id, $product->name, 700, 1)->create();

        Sanctum::actingAs($admin);
        $this->postJson('/api/orders/'.$order->id.'/approve')->assertOk();

        $response = $this->getJson('/api/journal-entries');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonStructure([
                'data' => [[
                    'entry_number',
                    'event',
                    'description',
                    'lines' => [[
                        'account' => ['code', 'name'],
                        'debit',
                        'credit',
                    ]],
                ]],
            ]);
    }

    public function test_invoice_payment_with_cashbox_creates_cash_transaction_and_is_returned_in_invoice_payload(): void
    {
        [$tenant, $branch, $admin] = $this->createAdminContext();
        $cashbox = Cashbox::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => 'Front Desk',
            'opening_balance' => 100,
            'current_balance' => 100,
            'is_active' => true,
            'is_primary' => true,
        ]);
        $customer = Customer::factory()->forTenantBranch($tenant->id, $branch->id)->create();
        $product = Product::factory()->forTenant($tenant->id)->create([
            'cash_price' => 500,
            'cost_price' => 250,
        ]);
        Inventory::create([
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'quantity' => 2,
        ]);

        $order = Order::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sales_agent_id' => $admin->id,
            'order_number' => 'ORD-TST-004',
            'type' => 'cash',
            'status' => 'draft',
            'subtotal' => 500,
            'discount_amount' => 0,
            'total' => 500,
        ]);
        OrderItem::factory()->forOrderProduct($order->id, $product->id, $product->name, 500, 1)->create();

        Sanctum::actingAs($admin);
        $this->postJson('/api/orders/'.$order->id.'/approve')->assertOk();

        $invoice = Invoice::query()->where('order_id', $order->id)->firstOrFail();

        $this->postJson('/api/invoices/'.$invoice->id.'/payments', [
            'amount' => 500,
            'payment_method' => 'cash',
            'cashbox_id' => $cashbox->id,
            'payment_date' => now()->toDateString(),
        ])->assertCreated();

        $payment = Payment::query()->where('invoice_id', $invoice->id)->firstOrFail();

        $this->assertDatabaseHas('cash_transactions', [
            'reference_type' => Payment::class,
            'reference_id' => $payment->id,
            'cashbox_id' => $cashbox->id,
        ]);

        $show = $this->getJson('/api/invoices/'.$invoice->id);
        $show->assertOk()
            ->assertJsonPath('data.payments.0.receipt_number', $payment->receipt_number)
            ->assertJsonPath('data.payments.0.payment_method', 'cash');
    }

    /**
     * @return array{0: Tenant, 1: Branch, 2: User}
     */
    private function createAdminContext(): array
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $admin = User::factory()->forTenant($tenant->id, $branch->id)->create();
        $admin->assignRole('company_admin');

        return [$tenant, $branch, $admin];
    }

    private function lineAmount(JournalEntry $entry, string $systemKey, string $column): float
    {
        $accountId = Account::query()
            ->where('tenant_id', $entry->tenant_id)
            ->where('system_key', $systemKey)
            ->value('id');

        return round((float) $entry->lines()->where('account_id', $accountId)->sum($column), 2);
    }
}
