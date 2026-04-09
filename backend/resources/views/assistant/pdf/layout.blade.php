<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 22px; }
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #111827;
            font-size: 12px;
            line-height: 1.55;
        }
        .header { border-bottom: 1px solid #d1d5db; padding-bottom: 12px; margin-bottom: 18px; }
        .header-table, .meta-table, .info-table, .data-table { width: 100%; border-collapse: collapse; }
        .brand { font-size: 18px; font-weight: bold; margin: 0 0 4px; }
        .muted { color: #6b7280; font-size: 11px; }
        .title { font-size: 19px; font-weight: bold; margin: 0 0 4px; text-align: {{ $rtl ? 'left' : 'right' }}; }
        .subtitle { color: #374151; font-size: 11px; text-align: {{ $rtl ? 'left' : 'right' }}; }
        .section-title { font-size: 13px; font-weight: bold; margin: 18px 0 8px; }
        .card {
            border: 1px solid #e5e7eb;
            background: #f9fafb;
            padding: 10px;
            margin-bottom: 12px;
        }
        .meta-table td { vertical-align: top; padding: 4px 0; }
        .label { color: #6b7280; width: 28%; }
        .value-strong { font-weight: bold; }
        .stats { width: 100%; border-collapse: separate; border-spacing: 8px 0; margin: 8px 0 14px; }
        .stats td {
            border: 1px solid #e5e7eb;
            background: #f9fafb;
            padding: 10px;
            vertical-align: top;
        }
        .stats-label { color: #6b7280; font-size: 10px; margin-bottom: 4px; }
        .stats-value { font-size: 14px; font-weight: bold; }
        .data-table th, .data-table td {
            border: 1px solid #d1d5db;
            padding: 6px 8px;
            vertical-align: top;
        }
        .data-table th {
            background: #f3f4f6;
            font-weight: bold;
        }
        .text-end { text-align: {{ $rtl ? 'left' : 'right' }}; }
        .text-start { text-align: {{ $rtl ? 'right' : 'left' }}; }
        .footer {
            border-top: 1px solid #e5e7eb;
            margin-top: 18px;
            padding-top: 8px;
            color: #6b7280;
            font-size: 10px;
            text-align: center;
        }
        .spacer { height: 10px; }
    </style>
</head>
<body>
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
@endphp

<div class="header">
    <table class="header-table">
        <tr>
            <td style="width: 58%;">
                <div class="brand">{{ $company['name'] ?: 'ERP' }}</div>
                @if(! empty($company['phone']))
                    <div class="muted">{{ $company['phone'] }}</div>
                @endif
                @if(! empty($company['email']))
                    <div class="muted">{{ $company['email'] }}</div>
                @endif
                @if(! empty($company['address']))
                    <div class="muted">{{ $company['address'] }}</div>
                @endif
                @if(! empty($company['cr_number']))
                    <div class="muted">{{ $locale === 'en' ? 'Commercial registration no.' : 'رقم السجل التجاري' }}: {{ $company['cr_number'] }}</div>
                @endif
                @if(! empty($company['license_number']))
                    <div class="muted">{{ $locale === 'en' ? 'Trade license no.' : 'رقم الرخصة التجارية' }}: {{ $company['license_number'] }}</div>
                @endif
                @if(! empty($company['tax_card_number']))
                    <div class="muted">{{ $locale === 'en' ? 'Tax card no.' : 'رقم البطاقة الضريبية' }}: {{ $company['tax_card_number'] }}</div>
                @endif
            </td>
            <td style="width: 42%;">
                <div class="title">{{ $title }}</div>
                @if(! empty($subtitle))
                    <div class="subtitle">{{ $subtitle }}</div>
                @endif
                <div class="subtitle">
                    {{ $locale === 'en' ? 'Generated at' : 'تاريخ الإنشاء' }}:
                    {{ \Illuminate\Support\Carbon::parse($generatedAt)->format('Y-m-d H:i') }}
                </div>
            </td>
        </tr>
    </table>
</div>

@yield('content')

<div class="footer">
    {{ $locale === 'en' ? 'Generated automatically by the ERP assistant.' : 'تم إنشاء هذا المستند تلقائياً بواسطة الوكيل الذكي.' }}
</div>
</body>
</html>
