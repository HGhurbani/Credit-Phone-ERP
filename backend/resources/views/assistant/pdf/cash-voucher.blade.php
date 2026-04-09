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
    $voucher = $payload;
@endphp

<table class="stats">
    <tr>
        <td>
            <div class="stats-label">{{ $locale === 'en' ? 'Amount' : 'المبلغ' }}</div>
            <div class="stats-value">{{ $fmtMoney($voucher->amount) }}</div>
        </td>
        <td>
            <div class="stats-label">{{ $locale === 'en' ? 'Date' : 'التاريخ' }}</div>
            <div class="stats-value">{{ $fmtDate($voucher->transaction_date) }}</div>
        </td>
    </tr>
</table>

<table class="meta-table">
    <tr>
        <td class="label">{{ $locale === 'en' ? 'Voucher number' : 'رقم السند' }}</td>
        <td>{{ $voucher->voucher_number }}</td>
        <td class="label">{{ $locale === 'en' ? 'Direction' : 'الاتجاه' }}</td>
        <td>{{ $voucher->direction === 'in' ? ($locale === 'en' ? 'In' : 'قبض') : ($locale === 'en' ? 'Out' : 'صرف') }}</td>
    </tr>
    <tr>
        <td class="label">{{ $locale === 'en' ? 'Cashbox' : 'الصندوق' }}</td>
        <td>{{ $voucher->cashbox?->name ?? '—' }}</td>
        <td class="label">{{ $locale === 'en' ? 'Branch' : 'الفرع' }}</td>
        <td>{{ $voucher->branch?->name ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">{{ $locale === 'en' ? 'Transaction type' : 'نوع الحركة' }}</td>
        <td>{{ $voucher->transaction_type }}</td>
        <td class="label">{{ $locale === 'en' ? 'Created by' : 'أنشئ بواسطة' }}</td>
        <td>{{ $voucher->createdBy?->name ?? '—' }}</td>
    </tr>
    @if($voucher->notes)
        <tr>
            <td class="label">{{ $locale === 'en' ? 'Notes' : 'ملاحظات' }}</td>
            <td colspan="3">{{ $voucher->notes }}</td>
        </tr>
    @endif
</table>
@endsection
