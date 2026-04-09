<?php

namespace App\Http\Resources;

use App\Models\CashTransaction;
use App\Models\Expense;
use App\Models\GoodsReceipt;
use App\Models\InstallmentContract;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entry_number' => $this->entry_number,
            'entry_date' => $this->entry_date?->toDateString(),
            'event' => $this->event,
            'description' => $this->description,
            'source_type' => class_basename((string) $this->source_type),
            'source_id' => $this->source_id,
            'source_reference' => $this->sourceReference(),
            'source_meta' => $this->sourceMeta(),
            'status' => $this->status,
            'posted_at' => $this->posted_at?->toDateTimeString(),
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ]),
            'created_by' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),
            'reversed_entry' => $this->whenLoaded('reversedEntry', fn () => $this->reversedEntry ? [
                'id' => $this->reversedEntry->id,
                'entry_number' => $this->reversedEntry->entry_number,
            ] : null),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'description' => $line->description,
                'debit' => (string) $line->debit,
                'credit' => (string) $line->credit,
                'sort_order' => $line->sort_order,
                'account' => $line->relationLoaded('account') && $line->account ? [
                    'id' => $line->account->id,
                    'code' => $line->account->code,
                    'name' => $line->account->name,
                    'type' => $line->account->type,
                    'system_key' => $line->account->system_key,
                ] : null,
            ])->values()),
            'totals' => [
                'debit' => (string) $this->lines->sum('debit'),
                'credit' => (string) $this->lines->sum('credit'),
            ],
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }

    private function sourceReference(): ?string
    {
        if (! $this->resource->relationLoaded('source') || ! $this->source) {
            return null;
        }

        $source = $this->source;

        return match (true) {
            $source instanceof Invoice => $source->invoice_number,
            $source instanceof InstallmentContract => $source->contract_number,
            $source instanceof Payment => $source->receipt_number,
            $source instanceof Expense => $source->expense_number,
            $source instanceof GoodsReceipt => $source->receipt_number,
            $source instanceof CashTransaction => $source->voucher_number,
            default => null,
        };
    }

    private function sourceMeta(): ?array
    {
        if (! $this->resource->relationLoaded('source') || ! $this->source) {
            return null;
        }

        $source = $this->source;

        if ($source instanceof CashTransaction) {
            return [
                'transaction_type' => $source->transaction_type,
                'direction' => $source->direction,
            ];
        }

        return null;
    }
}
