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
    $invoice = $payload;
    $items = $invoice->items ?? collect();
@endphp

<table class="meta-table">
    <tr>
        <td class="label">{{ $locale === 'en' ? 'Customer' : 'العميل' }}</td>
        <td>{{ $invoice->customer?->name ?? '—' }}</td>
        <td class="label">{{ $locale === 'en' ? 'Issue date' : 'تاريخ الإصدار' }}</td>
        <td>{{ $fmtDate($invoice->issue_date) }}</td>
    </tr>
    <tr>
        <td class="label">{{ $locale === 'en' ? 'Branch' : 'الفرع' }}</td>
        <td>{{ $invoice->branch?->name ?? '—' }}</td>
        <td class="label">{{ $locale === 'en' ? 'Due date' : 'تاريخ الاستحقاق' }}</td>
        <td>{{ $fmtDate($invoice->due_date) }}</td>
    </tr>
    <tr>
        <td class="label">{{ $locale === 'en' ? 'Contract' : 'العقد' }}</td>
        <td>{{ $invoice->contract?->contract_number ?? '—' }}</td>
        <td class="label">{{ $locale === 'en' ? 'Status' : 'الحالة' }}</td>
        <td>{{ $invoice->status }}</td>
    </tr>
</table>

<div class="section-title">{{ $locale === 'en' ? 'Items' : 'البنود' }}</div>
<table class="data-table">
    <thead>
    <tr>
        <th class="text-start">{{ $locale === 'en' ? 'Description' : 'الوصف' }}</th>
        <th class="text-end">{{ $locale === 'en' ? 'Qty' : 'الكمية' }}</th>
        <th class="text-end">{{ $locale === 'en' ? 'Total' : 'الإجمالي' }}</th>
    </tr>
    </thead>
    <tbody>
    @forelse($items as $item)
        <tr>
            <td>{{ $item->description }}</td>
            <td class="text-end">{{ $item->quantity }}</td>
            <td class="text-end">{{ $fmtMoney($item->total) }}</td>
        </tr>
    @empty
        <tr><td colspan="3" class="text-start">{{ $locale === 'en' ? 'No items' : 'لا توجد بنود' }}</td></tr>
    @endforelse
    </tbody>
</table>

<table class="meta-table" style="margin-top: 14px;">
    <tr>
        <td class="label">{{ $locale === 'en' ? 'Subtotal' : 'الإجمالي الفرعي' }}</td>
        <td>{{ $fmtMoney($invoice->subtotal) }}</td>
        <td class="label">{{ $locale === 'en' ? 'Total' : 'الإجمالي' }}</td>
        <td class="value-strong">{{ $fmtMoney($invoice->total) }}</td>
    </tr>
    <tr>
        <td class="label">{{ $locale === 'en' ? 'Discount' : 'الخصم' }}</td>
        <td>{{ $fmtMoney($invoice->discount_amount) }}</td>
        <td class="label">{{ $locale === 'en' ? 'Paid' : 'المدفوع' }}</td>
        <td>{{ $fmtMoney($invoice->paid_amount) }}</td>
    </tr>
    <tr>
        <td class="label">{{ $locale === 'en' ? 'Remaining' : 'المتبقي' }}</td>
        <td>{{ $fmtMoney($invoice->remaining_amount) }}</td>
        <td class="label"></td>
        <td></td>
    </tr>
</table>
@endsection
