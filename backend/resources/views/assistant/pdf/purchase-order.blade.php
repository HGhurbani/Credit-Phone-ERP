@extends('assistant.pdf.layout')

@section('content')
@php
    $fmtMoney = function ($value) {
        $formatted = number_format((float) $value, 2, '.', ',');
        return rtrim(rtrim($formatted, '0'), '.');
    };
    $fmtDate = function ($value) {
        if (! $value) {
            return '—';
        }

        return \Illuminate\Support\Carbon::parse($value)->format('Y-m-d');
    };
    $po = $payload;
    $items = $po->items ?? collect();
    $receipts = $po->goodsReceipts ?? collect();
@endphp

<table class="meta-table">
    <tr>
        <td class="label">{{ $locale === 'en' ? 'Supplier' : 'المورد' }}</td>
        <td>{{ $po->supplier?->name ?? '—' }}</td>
        <td class="label">{{ $locale === 'en' ? 'Branch' : 'الفرع' }}</td>
        <td>{{ $po->branch?->name ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">{{ $locale === 'en' ? 'Order date' : 'تاريخ الطلب' }}</td>
        <td>{{ $fmtDate($po->order_date) }}</td>
        <td class="label">{{ $locale === 'en' ? 'Expected date' : 'التاريخ المتوقع' }}</td>
        <td>{{ $fmtDate($po->expected_date) }}</td>
    </tr>
    <tr>
        <td class="label">{{ $locale === 'en' ? 'Status' : 'الحالة' }}</td>
        <td>{{ $po->status }}</td>
        <td class="label">{{ $locale === 'en' ? 'Total' : 'الإجمالي' }}</td>
        <td>{{ $fmtMoney($po->total) }}</td>
    </tr>
    @if($po->notes)
        <tr>
            <td class="label">{{ $locale === 'en' ? 'Notes' : 'ملاحظات' }}</td>
            <td colspan="3">{{ $po->notes }}</td>
        </tr>
    @endif
</table>

<div class="section-title">{{ $locale === 'en' ? 'Order lines' : 'بنود الطلب' }}</div>
<table class="data-table">
    <thead>
    <tr>
        <th class="text-start">{{ $locale === 'en' ? 'Product' : 'المنتج' }}</th>
        <th class="text-end">{{ $locale === 'en' ? 'Qty' : 'الكمية' }}</th>
        <th class="text-end">{{ $locale === 'en' ? 'Received' : 'المستلم' }}</th>
        <th class="text-end">{{ $locale === 'en' ? 'Unit cost' : 'تكلفة الوحدة' }}</th>
        <th class="text-end">{{ $locale === 'en' ? 'Total' : 'الإجمالي' }}</th>
    </tr>
    </thead>
    <tbody>
    @forelse($items as $item)
        <tr>
            <td>{{ $item->product?->name ?? $item->product?->name_ar ?? '—' }}</td>
            <td class="text-end">{{ $item->quantity }}</td>
            <td class="text-end">{{ $item->quantity_received ?? 0 }}</td>
            <td class="text-end">{{ $fmtMoney($item->unit_cost) }}</td>
            <td class="text-end">{{ $fmtMoney($item->total) }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="text-start">{{ $locale === 'en' ? 'No items' : 'لا توجد بنود' }}</td></tr>
    @endforelse
    </tbody>
</table>

<div class="section-title">{{ $locale === 'en' ? 'Goods receipts' : 'سندات الاستلام' }}</div>
@forelse($receipts as $receipt)
    <div class="card">
        <div class="value-strong">{{ $receipt->receipt_number }}</div>
        <div class="muted">
            {{ $receipt->branch?->name ?? '—' }}
            @if($receipt->receivedBy?->name) | {{ $receipt->receivedBy->name }} @endif
            @if($receipt->received_at) | {{ $fmtDate($receipt->received_at) }} @endif
        </div>
        @if(($receipt->items ?? collect())->count() > 0)
            <table class="data-table" style="margin-top: 8px;">
                <thead>
                <tr>
                    <th class="text-start">{{ $locale === 'en' ? 'Product' : 'المنتج' }}</th>
                    <th class="text-end">{{ $locale === 'en' ? 'Qty received' : 'الكمية المستلمة' }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($receipt->items as $receiptItem)
                    <tr>
                        <td>{{ $receiptItem->purchaseOrderItem?->product?->name ?? $receiptItem->purchaseOrderItem?->product?->name_ar ?? '—' }}</td>
                        <td class="text-end">{{ $receiptItem->quantity }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
        @if($receipt->notes)
            <div class="muted" style="margin-top: 6px;">{{ $receipt->notes }}</div>
        @endif
    </div>
@empty
    <div class="muted">{{ $locale === 'en' ? 'No goods receipts' : 'لا توجد سندات استلام' }}</div>
@endforelse
@endsection
