<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'national_id' => $this->national_id,
            'id_type' => $this->id_type,
            'address' => $this->address,
            'city' => $this->city,
            'employer_name' => $this->employer_name,
            'monthly_salary' => $this->monthly_salary,
            'credit_score' => $this->credit_score,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'tenant_id' => $this->tenant_id,
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn() => ['id' => $this->branch->id, 'name' => $this->branch->name]),
            'created_by' => $this->whenLoaded('createdBy', fn() => ['id' => $this->createdBy->id, 'name' => $this->createdBy->name]),
            'guarantors' => $this->whenLoaded('guarantors'),
            'documents' => $this->whenLoaded('documents', fn() => $this->documents->map(fn($d) => [
                'id' => $d->id,
                'type' => $d->type,
                'title' => $d->title,
                'file_name' => $d->file_name,
                'url' => $d->url,
                'created_at' => $d->created_at->toDateTimeString(),
            ])),
            'notes_list' => $this->whenLoaded('notes', fn() => $this->notes->map(fn($n) => [
                'id' => $n->id,
                'note' => $n->note,
                'created_by' => $n->createdBy?->name,
                'created_at' => $n->created_at->toDateTimeString(),
            ])),
            'orders_summary' => $this->whenLoaded('orders', fn() => [
                'total' => $this->orders->count(),
                'latest' => $this->orders->first(),
            ]),
            'contracts_summary' => $this->whenLoaded('contracts', fn() => [
                'total' => $this->contracts->count(),
                'active' => $this->contracts->where('status', 'active')->count(),
            ]),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
