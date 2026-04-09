<?php

namespace App\Services;

use App\Models\Account;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountCatalogService
{
    /**
     * @return array<int, array{code: string, name: string, type: string, system_key: string}>
     */
    private function defaults(): array
    {
        return [
            ['code' => '1010', 'name' => 'Cash on Hand', 'type' => 'asset', 'system_key' => 'cash_on_hand'],
            ['code' => '1020', 'name' => 'Bank Clearing', 'type' => 'asset', 'system_key' => 'bank_clearing'],
            ['code' => '1100', 'name' => 'Trade Receivables', 'type' => 'asset', 'system_key' => 'accounts_receivable_trade'],
            ['code' => '1110', 'name' => 'Installment Receivables', 'type' => 'asset', 'system_key' => 'accounts_receivable_installment'],
            ['code' => '1200', 'name' => 'Inventory', 'type' => 'asset', 'system_key' => 'inventory'],
            ['code' => '2100', 'name' => 'Accounts Payable', 'type' => 'liability', 'system_key' => 'accounts_payable'],
            ['code' => '2110', 'name' => 'Goods Received Not Billed', 'type' => 'liability', 'system_key' => 'goods_received_not_billed'],
            ['code' => '4100', 'name' => 'Cash Sales Revenue', 'type' => 'revenue', 'system_key' => 'sales_revenue_cash'],
            ['code' => '4110', 'name' => 'Installment Sales Revenue', 'type' => 'revenue', 'system_key' => 'sales_revenue_installment'],
            ['code' => '5100', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'system_key' => 'cost_of_goods_sold'],
            ['code' => '6100', 'name' => 'General Expense', 'type' => 'expense', 'system_key' => 'general_expense'],
            ['code' => '6110', 'name' => 'Other Expense', 'type' => 'expense', 'system_key' => 'other_expense'],
            ['code' => '6120', 'name' => 'Cash Over Short', 'type' => 'expense', 'system_key' => 'cash_over_short'],
            ['code' => '7100', 'name' => 'Other Income', 'type' => 'revenue', 'system_key' => 'other_income'],
        ];
    }

    /**
     * @return Collection<string, Account>
     */
    public function ensureDefaults(int $tenantId): Collection
    {
        return DB::transaction(function () use ($tenantId) {
            foreach ($this->defaults() as $account) {
                Account::query()->firstOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'system_key' => $account['system_key'],
                    ],
                    [
                        'code' => $account['code'],
                        'name' => $account['name'],
                        'type' => $account['type'],
                        'is_active' => true,
                    ]
                );
            }

            return Account::query()
                ->forTenant($tenantId)
                ->whereNotNull('system_key')
                ->get()
                ->keyBy('system_key');
        });
    }

    public function getBySystemKey(int $tenantId, string $systemKey): Account
    {
        $accounts = $this->ensureDefaults($tenantId);
        $account = $accounts->get($systemKey);

        if (! $account instanceof Account) {
            throw new \RuntimeException('Missing accounting system account: '.$systemKey);
        }

        return $account;
    }

    public function paymentAssetAccount(int $tenantId, string $paymentMethod): Account
    {
        $paymentMethod = strtolower(trim($paymentMethod));

        return $this->getBySystemKey(
            $tenantId,
            $paymentMethod === 'cash' ? 'cash_on_hand' : 'bank_clearing'
        );
    }
}
