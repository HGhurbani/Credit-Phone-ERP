<?php

namespace App\Services\Assistant;

class TelegramAssistantResponseFormatter
{
    private const MAX_MESSAGE_LENGTH = 3500;

    /**
     * @return array<int, string>
     */
    public function format(array $executionResult, ?string $assistantMessage, string $locale = 'ar'): array
    {
        $summary = trim((string) ($assistantMessage ?: ($executionResult['summary'] ?? '')));
        $data = isset($executionResult['data']) && is_array($executionResult['data'])
            ? $executionResult['data']
            : [];

        $lines = $summary !== '' ? [$summary] : [];

        if (isset($data['print_document']) && is_array($data['print_document'])) {
            $lines = array_merge($lines, $this->formatPrintDocument($data['print_document'], $locale));
        } elseif ($this->looksLikeReportData($data)) {
            $lines = array_merge($lines, $this->formatReportData($data, $locale));
        }

        if ($lines === []) {
            return ['OK'];
        }

        return $this->chunkLines($lines);
    }

    /**
     * @return array<int, string>
     */
    private function formatPrintDocument(array $document, string $locale): array
    {
        $lines = [''];
        $lines[] = $locale === 'en' ? 'Document details:' : 'تفاصيل المستند:';

        if (! empty($document['filename'])) {
            $lines[] = $this->labelValue('filename', (string) $document['filename'], $locale);
        }

        if (! empty($document['url'])) {
            $lines[] = $this->labelValue('open_url', (string) $document['url'], $locale);
        }

        if (! empty($document['download_url'])) {
            $lines[] = $this->labelValue('download_url', (string) $document['download_url'], $locale);
        }

        return $lines;
    }

    private function looksLikeReportData(array $data): bool
    {
        return isset($data['summary']) && is_array($data['summary']);
    }

    /**
     * @return array<int, string>
     */
    private function formatReportData(array $data, string $locale): array
    {
        $lines = [''];
        $lines[] = $locale === 'en' ? 'Report summary:' : 'ملخص التقرير:';

        foreach ($data['summary'] as $key => $value) {
            $formatted = $this->formatSummaryValue($key, $value, $locale);
            if ($formatted !== null) {
                $lines[] = $formatted;
            }
        }

        if (! empty($data['daily_breakdown']) && is_array($data['daily_breakdown'])) {
            $lines[] = '';
            $lines[] = $locale === 'en' ? 'Daily breakdown:' : 'التفصيل اليومي:';

            foreach (array_slice($data['daily_breakdown'], 0, 5) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $lines[] = '- '.$this->compactRow($row, $locale);
            }
        }

        if (! empty($data['items']) && is_array($data['items'])) {
            $lines[] = '';
            $lines[] = $locale === 'en' ? 'Top results:' : 'أبرز النتائج:';

            foreach (array_slice($data['items'], 0, 5) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $lines[] = ($index + 1).'. '.$this->compactRow($item, $locale);
            }

            if (count($data['items']) > 5) {
                $remaining = count($data['items']) - 5;
                $lines[] = $locale === 'en'
                    ? "... and {$remaining} more result(s)."
                    : "... ويوجد {$remaining} نتيجة إضافية.";
            }
        }

        return $lines;
    }

    private function formatSummaryValue(string $key, mixed $value, string $locale): ?string
    {
        if (is_array($value)) {
            $compact = $this->compactRow($value, $locale);

            return $compact === '' ? null : $this->labelValue($key, $compact, $locale);
        }

        if ($value === null || $value === '') {
            return null;
        }

        return $this->labelValue($key, $this->stringifyValue($value), $locale);
    }

    private function compactRow(array $row, string $locale): string
    {
        $parts = [];

        foreach ($row as $key => $value) {
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }

            $parts[] = $this->translatedLabel($key, $locale).': '.$this->stringifyValue($value);
        }

        return implode(' | ', array_slice($parts, 0, 6));
    }

    private function labelValue(string $key, string $value, string $locale): string
    {
        return $this->translatedLabel($key, $locale).': '.$value;
    }

    private function translatedLabel(string $key, string $locale): string
    {
        $labels = [
            'total_orders' => ['ar' => 'إجمالي الطلبات', 'en' => 'Total orders'],
            'total_revenue' => ['ar' => 'إجمالي الإيراد', 'en' => 'Total revenue'],
            'cash_revenue' => ['ar' => 'إيراد النقد', 'en' => 'Cash revenue'],
            'installment_revenue' => ['ar' => 'إيراد التقسيط', 'en' => 'Installment revenue'],
            'avg_order_value' => ['ar' => 'متوسط قيمة الطلب', 'en' => 'Average order value'],
            'total_payments' => ['ar' => 'إجمالي الدفعات', 'en' => 'Total payments'],
            'total_collected' => ['ar' => 'إجمالي التحصيل', 'en' => 'Total collected'],
            'avg_payment_value' => ['ar' => 'متوسط قيمة الدفعة', 'en' => 'Average payment value'],
            'total' => ['ar' => 'الإجمالي', 'en' => 'Total'],
            'total_remaining' => ['ar' => 'المتبقي', 'en' => 'Remaining total'],
            'total_paid' => ['ar' => 'المدفوع', 'en' => 'Paid total'],
            'portfolio_value' => ['ar' => 'قيمة المحفظة', 'en' => 'Portfolio value'],
            'avg_monthly_amount' => ['ar' => 'متوسط القسط الشهري', 'en' => 'Average monthly amount'],
            'total_overdue' => ['ar' => 'إجمالي المتأخرات', 'en' => 'Total overdue'],
            'unique_contracts' => ['ar' => 'العقود المتأثرة', 'en' => 'Affected contracts'],
            'avg_days_overdue' => ['ar' => 'متوسط أيام التأخير', 'en' => 'Average overdue days'],
            'branches_count' => ['ar' => 'عدد الفروع', 'en' => 'Branches count'],
            'agents_count' => ['ar' => 'عدد الموظفين', 'en' => 'Agents count'],
            'top_branch' => ['ar' => 'أفضل فرع', 'en' => 'Top branch'],
            'top_agent' => ['ar' => 'أفضل موظف', 'en' => 'Top agent'],
            'filename' => ['ar' => 'اسم الملف', 'en' => 'Filename'],
            'open_url' => ['ar' => 'رابط العرض', 'en' => 'Open URL'],
            'download_url' => ['ar' => 'رابط التنزيل', 'en' => 'Download URL'],
            'date' => ['ar' => 'التاريخ', 'en' => 'Date'],
            'report_date' => ['ar' => 'التاريخ', 'en' => 'Date'],
            'order_number' => ['ar' => 'رقم الطلب', 'en' => 'Order number'],
            'receipt_number' => ['ar' => 'رقم الإيصال', 'en' => 'Receipt number'],
            'payment_date' => ['ar' => 'تاريخ الدفعة', 'en' => 'Payment date'],
            'amount' => ['ar' => 'المبلغ', 'en' => 'Amount'],
            'payment_method' => ['ar' => 'طريقة الدفع', 'en' => 'Payment method'],
            'customer' => ['ar' => 'العميل', 'en' => 'Customer'],
            'contract' => ['ar' => 'العقد', 'en' => 'Contract'],
            'branch' => ['ar' => 'الفرع', 'en' => 'Branch'],
            'sales_agent' => ['ar' => 'مندوب المبيعات', 'en' => 'Sales agent'],
            'name' => ['ar' => 'الاسم', 'en' => 'Name'],
            'code' => ['ar' => 'الكود', 'en' => 'Code'],
            'users_count' => ['ar' => 'عدد المستخدمين', 'en' => 'Users count'],
            'total_sales' => ['ar' => 'إجمالي المبيعات', 'en' => 'Total sales'],
            'total_collections' => ['ar' => 'إجمالي التحصيلات', 'en' => 'Total collections'],
            'cash_sales' => ['ar' => 'مبيعات النقد', 'en' => 'Cash sales'],
            'installment_sales' => ['ar' => 'مبيعات التقسيط', 'en' => 'Installment sales'],
            'type' => ['ar' => 'النوع', 'en' => 'Type'],
            'status' => ['ar' => 'الحالة', 'en' => 'Status'],
            'id' => ['ar' => 'المعرف', 'en' => 'ID'],
        ];

        if (isset($labels[$key][$locale])) {
            return $labels[$key][$locale];
        }

        $normalized = str_replace('_', ' ', $key);

        return $locale === 'en'
            ? ucwords($normalized)
            : $normalized;
    }

    private function stringifyValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_float($value)) {
            $formatted = number_format($value, 2, '.', '');

            return rtrim(rtrim($formatted, '0'), '.');
        }

        return trim((string) $value);
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private function chunkLines(array $lines): array
    {
        $messages = [];
        $current = '';

        foreach ($lines as $line) {
            $next = $current === '' ? $line : $current."\n".$line;

            if (mb_strlen($next) <= self::MAX_MESSAGE_LENGTH) {
                $current = $next;

                continue;
            }

            if ($current !== '') {
                $messages[] = trim($current);
            }

            $current = $line;
        }

        if ($current !== '') {
            $messages[] = trim($current);
        }

        return $messages === [] ? ['OK'] : $messages;
    }
}
