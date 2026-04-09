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
    $contract = $payload;
    $schedules = $contract->schedules ?? collect();
@endphp

<table class="meta-table">
    <tr>
        <td class="label">{{ $locale === 'en' ? 'Customer' : 'العميل' }}</td>
        <td>{{ $contract->customer?->name ?? '—' }}</td>
        <td class="label">{{ $locale === 'en' ? 'Branch' : 'الفرع' }}</td>
        <td>{{ $contract->branch?->name ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">{{ $locale === 'en' ? 'Status' : 'الحالة' }}</td>
        <td>{{ $contract->status }}</td>
        <td class="label">{{ $locale === 'en' ? 'Duration' : 'المدة' }}</td>
        <td>{{ $contract->duration_months }} {{ $locale === 'en' ? 'months' : 'شهر' }}</td>
    </tr>
    <tr>
        <td class="label">{{ $locale === 'en' ? 'Start date' : 'تاريخ البداية' }}</td>
        <td>{{ $fmtDate($contract->start_date) }}</td>
        <td class="label">{{ $locale === 'en' ? 'End date' : 'تاريخ النهاية' }}</td>
        <td>{{ $fmtDate($contract->end_date) }}</td>
    </tr>
</table>

<table class="stats">
    <tr>
        <td><div class="stats-label">{{ $locale === 'en' ? 'Financed amount' : 'المبلغ الممول' }}</div><div class="stats-value">{{ $fmtMoney($contract->financed_amount) }}</div></td>
        <td><div class="stats-label">{{ $locale === 'en' ? 'Down payment' : 'الدفعة الأولى' }}</div><div class="stats-value">{{ $fmtMoney($contract->down_payment) }}</div></td>
        <td><div class="stats-label">{{ $locale === 'en' ? 'Monthly installment' : 'القسط الشهري' }}</div><div class="stats-value">{{ $fmtMoney($contract->monthly_amount) }}</div></td>
        <td><div class="stats-label">{{ $locale === 'en' ? 'Remaining' : 'المتبقي' }}</div><div class="stats-value">{{ $fmtMoney($contract->remaining_amount) }}</div></td>
    </tr>
</table>

<div class="section-title">{{ $locale === 'en' ? 'Installment schedule' : 'جدول الأقساط' }}</div>
<table class="data-table">
    <thead>
    <tr>
        <th>#</th>
        <th class="text-start">{{ $locale === 'en' ? 'Due date' : 'الاستحقاق' }}</th>
        <th class="text-end">{{ $locale === 'en' ? 'Amount' : 'المبلغ' }}</th>
        <th class="text-end">{{ $locale === 'en' ? 'Paid' : 'المدفوع' }}</th>
        <th class="text-end">{{ $locale === 'en' ? 'Remaining' : 'المتبقي' }}</th>
        <th class="text-start">{{ $locale === 'en' ? 'Status' : 'الحالة' }}</th>
    </tr>
    </thead>
    <tbody>
    @forelse($schedules as $schedule)
        <tr>
            <td>{{ $schedule->installment_number }}</td>
            <td>{{ $fmtDate($schedule->due_date) }}</td>
            <td class="text-end">{{ $fmtMoney($schedule->amount) }}</td>
            <td class="text-end">{{ $fmtMoney($schedule->paid_amount) }}</td>
            <td class="text-end">{{ $fmtMoney($schedule->remaining_amount) }}</td>
            <td>{{ $schedule->status }}</td>
        </tr>
    @empty
        <tr><td colspan="6" class="text-start">{{ $locale === 'en' ? 'No schedule found' : 'لا يوجد جدول أقساط' }}</td></tr>
    @endforelse
    </tbody>
</table>
@endsection
