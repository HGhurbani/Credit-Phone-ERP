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
    $customer = $payload['customer'] ?? [];
    $summary = $payload['summary'] ?? [];
    $contracts = $payload['active_contracts'] ?? [];
    $overdues = $payload['overdue_installments'] ?? [];
    $payments = $payload['latest_payments'] ?? [];
@endphp

<div class="card">
    <div class="value-strong">{{ $customer['name'] ?? '—' }}</div>
    <div class="muted">{{ $customer['phone'] ?? '' }}@if(!empty($customer['email'])) | {{ $customer['email'] }}@endif</div>
    @if(! empty($customer['branch']['name']))
        <div class="muted">{{ $locale === 'en' ? 'Branch' : 'الفرع' }}: {{ $customer['branch']['name'] }}</div>
    @endif
</div>

<table class="stats">
    <tr>
        <td>
            <div class="stats-label">{{ $locale === 'en' ? 'Total outstanding' : 'إجمالي المديونية' }}</div>
            <div class="stats-value">{{ $fmtMoney($summary['total_outstanding'] ?? 0) }}</div>
        </td>
        <td>
            <div class="stats-label">{{ $locale === 'en' ? 'Installments outstanding' : 'المتبقي بالأقساط' }}</div>
            <div class="stats-value">{{ $fmtMoney($summary['installments_outstanding'] ?? 0) }}</div>
        </td>
        <td>
            <div class="stats-label">{{ $locale === 'en' ? 'Invoice balance' : 'رصيد الفواتير' }}</div>
            <div class="stats-value">{{ $fmtMoney($summary['invoice_balance'] ?? 0) }}</div>
        </td>
        <td>
            <div class="stats-label">{{ $locale === 'en' ? 'Total paid' : 'إجمالي المدفوع' }}</div>
            <div class="stats-value">{{ $fmtMoney($summary['total_paid'] ?? 0) }}</div>
        </td>
    </tr>
</table>

<div class="section-title">{{ $locale === 'en' ? 'Active contracts' : 'العقود النشطة' }}</div>
<table class="data-table">
    <thead>
    <tr>
        <th class="text-start">{{ $locale === 'en' ? 'Contract' : 'العقد' }}</th>
        <th class="text-end">{{ $locale === 'en' ? 'Remaining' : 'المتبقي' }}</th>
        <th class="text-start">{{ $locale === 'en' ? 'Status' : 'الحالة' }}</th>
    </tr>
    </thead>
    <tbody>
    @forelse($contracts as $row)
        <tr>
            <td>{{ $row['contract_number'] ?? '—' }}</td>
            <td class="text-end">{{ $fmtMoney($row['remaining_amount'] ?? 0) }}</td>
            <td>{{ $row['status'] ?? '—' }}</td>
        </tr>
    @empty
        <tr><td colspan="3" class="text-start">{{ $locale === 'en' ? 'No data' : 'لا توجد بيانات' }}</td></tr>
    @endforelse
    </tbody>
</table>

<div class="section-title">{{ $locale === 'en' ? 'Overdue installments' : 'الأقساط المتأخرة' }}</div>
<table class="data-table">
    <thead>
    <tr>
        <th class="text-start">{{ $locale === 'en' ? 'Contract' : 'العقد' }}</th>
        <th class="text-start">{{ $locale === 'en' ? 'Due date' : 'الاستحقاق' }}</th>
        <th class="text-end">{{ $locale === 'en' ? 'Remaining' : 'المتبقي' }}</th>
    </tr>
    </thead>
    <tbody>
    @forelse($overdues as $row)
        <tr>
            <td>{{ $row['contract_number'] ?? '—' }}</td>
            <td>{{ $fmtDate($row['due_date'] ?? null) }}</td>
            <td class="text-end">{{ $fmtMoney($row['remaining_amount'] ?? 0) }}</td>
        </tr>
    @empty
        <tr><td colspan="3" class="text-start">{{ $locale === 'en' ? 'No data' : 'لا توجد بيانات' }}</td></tr>
    @endforelse
    </tbody>
</table>

<div class="section-title">{{ $locale === 'en' ? 'Latest payments' : 'آخر الدفعات' }}</div>
<table class="data-table">
    <thead>
    <tr>
        <th class="text-start">{{ $locale === 'en' ? 'Date' : 'التاريخ' }}</th>
        <th class="text-start">{{ $locale === 'en' ? 'Method' : 'طريقة الدفع' }}</th>
        <th class="text-start">{{ $locale === 'en' ? 'Receipt' : 'الإيصال' }}</th>
        <th class="text-end">{{ $locale === 'en' ? 'Amount' : 'المبلغ' }}</th>
    </tr>
    </thead>
    <tbody>
    @forelse($payments as $row)
        <tr>
            <td>{{ $fmtDate($row['payment_date'] ?? null) }}</td>
            <td>{{ $row['payment_method'] ?? '—' }}</td>
            <td>{{ $row['receipt_number'] ?? '—' }}</td>
            <td class="text-end">{{ $fmtMoney($row['amount'] ?? 0) }}</td>
        </tr>
    @empty
        <tr><td colspan="4" class="text-start">{{ $locale === 'en' ? 'No data' : 'لا توجد بيانات' }}</td></tr>
    @endforelse
    </tbody>
</table>
@endsection
