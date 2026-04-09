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
    $payment = $payload;
@endphp

<table class="stats">
    <tr>
        <td>
            <div class="stats-label">{{ $locale === 'en' ? 'Amount' : 'المبلغ' }}</div>
            <div class="stats-value">{{ $fmtMoney($payment->amount) }}</div>
        </td>
        <td>
            <div class="stats-label">{{ $locale === 'en' ? 'Payment date' : 'تاريخ الدفع' }}</div>
            <div class="stats-value">{{ $fmtDate($payment->payment_date) }}</div>
        </td>
    </tr>
</table>

<table class="meta-table">
    <tr>
        <td class="label">{{ $locale === 'en' ? 'Customer' : 'العميل' }}</td>
        <td>{{ $payment->customer?->name ?? '—' }}</td>
        <td class="label">{{ $locale === 'en' ? 'Method' : 'طريقة الدفع' }}</td>
        <td>{{ $payment->payment_method }}</td>
    </tr>
    <tr>
        <td class="label">{{ $locale === 'en' ? 'Contract' : 'العقد' }}</td>
        <td>{{ $payment->contract?->contract_number ?? '—' }}</td>
        <td class="label">{{ $locale === 'en' ? 'Branch' : 'الفرع' }}</td>
        <td>{{ $payment->branch?->name ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">{{ $locale === 'en' ? 'Invoice' : 'الفاتورة' }}</td>
        <td>{{ $payment->invoice?->invoice_number ?? '—' }}</td>
        <td class="label">{{ $locale === 'en' ? 'Collected by' : 'تم التحصيل بواسطة' }}</td>
        <td>{{ $payment->collectedBy?->name ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">{{ $locale === 'en' ? 'Reference' : 'المرجع' }}</td>
        <td>{{ $payment->reference_number ?: '—' }}</td>
        <td class="label">{{ $locale === 'en' ? 'Installment' : 'رقم القسط' }}</td>
        <td>{{ $payment->schedule?->installment_number ? '#'.$payment->schedule->installment_number : '—' }}</td>
    </tr>
    @if($payment->collector_notes)
        <tr>
            <td class="label">{{ $locale === 'en' ? 'Notes' : 'ملاحظات' }}</td>
            <td colspan="3">{{ $payment->collector_notes }}</td>
        </tr>
    @endif
</table>
@endsection
