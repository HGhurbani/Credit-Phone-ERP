<?php

namespace Tests\Unit\Assistant;

use App\Services\Assistant\TelegramAssistantResponseFormatter;
use Tests\TestCase;

class TelegramAssistantResponseFormatterTest extends TestCase
{
    public function test_it_formats_report_details_for_telegram(): void
    {
        $formatter = new TelegramAssistantResponseFormatter();

        $messages = $formatter->format([
            'status' => 'completed',
            'summary' => 'تم تشغيل تقرير المبيعات للفترة 2026-04-01 إلى 2026-04-30.',
            'data' => [
                'summary' => [
                    'total_orders' => 12,
                    'total_revenue' => 5400.5,
                ],
                'daily_breakdown' => [
                    ['date' => '2026-04-01', 'total_orders' => 4, 'total_revenue' => 1200],
                ],
                'items' => [
                    ['order_number' => 'ORD-100', 'customer' => 'Ahmed', 'total' => 800],
                    ['order_number' => 'ORD-101', 'customer' => 'Sara', 'total' => 950],
                ],
            ],
        ], null, 'ar');

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('ملخص التقرير', $messages[0]);
        $this->assertStringContainsString('إجمالي الطلبات: 12', $messages[0]);
        $this->assertStringContainsString('إجمالي الإيراد: 5400.5', $messages[0]);
        $this->assertStringContainsString('أبرز النتائج', $messages[0]);
        $this->assertStringContainsString('رقم الطلب: ORD-100', $messages[0]);
    }

    public function test_it_formats_print_document_metadata_for_telegram(): void
    {
        $formatter = new TelegramAssistantResponseFormatter();

        $messages = $formatter->format([
            'status' => 'completed',
            'summary' => 'العقد جاهز للطباعة PDF.',
            'data' => [
                'print_document' => [
                    'filename' => 'contract-25.pdf',
                    'url' => 'https://example.com/print/contract/25',
                    'download_url' => 'https://example.com/print/contract/25?autopdf=1&filename=contract-25.pdf',
                ],
            ],
        ], null, 'ar');

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('تفاصيل المستند', $messages[0]);
        $this->assertStringContainsString('اسم الملف: contract-25.pdf', $messages[0]);
        $this->assertStringContainsString('رابط التنزيل: https://example.com/print/contract/25?autopdf=1&filename=contract-25.pdf', $messages[0]);
    }
}
