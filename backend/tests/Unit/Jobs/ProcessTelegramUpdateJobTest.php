<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\AssistantMessage;
use App\Models\User;
use App\Services\Assistant\AssistantOrchestratorService;
use App\Services\Assistant\TelegramAssistantResponseFormatter;
use App\Services\Assistant\TelegramBotService;
use Mockery;
use Tests\TestCase;

class ProcessTelegramUpdateJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_sends_formatted_report_details_to_telegram(): void
    {
        $assistant = Mockery::mock(AssistantOrchestratorService::class);
        $telegram = Mockery::mock(TelegramBotService::class);
        $formatter = new TelegramAssistantResponseFormatter();
        $user = new User(['locale' => 'ar']);

        $message = new AssistantMessage([
            'assistant_message' => 'تم تشغيل تقرير المبيعات للفترة 2026-04-01 إلى 2026-04-30.',
            'execution_result_json' => [
                'status' => 'completed',
                'summary' => 'تم تشغيل تقرير المبيعات للفترة 2026-04-01 إلى 2026-04-30.',
                'data' => [
                    'summary' => [
                        'total_orders' => 3,
                        'total_revenue' => 1500,
                    ],
                ],
            ],
        ]);

        $assistant->shouldReceive('getLinkedUserByTelegram')
            ->once()
            ->with(5, '77')
            ->andReturn($user);

        $assistant->shouldReceive('processMessage')
            ->once()
            ->with($user, 'ارسل تقرير المبيعات', 'telegram')
            ->andReturn(['message' => $message]);

        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (int $tenantId, string $chatId, string $text): bool {
                return $tenantId === 5
                    && $chatId === '900'
                    && str_contains($text, 'ملخص التقرير')
                    && str_contains($text, 'إجمالي الطلبات: 3');
            });

        $job = new ProcessTelegramUpdateJob(5, [
            'message' => [
                'text' => 'ارسل تقرير المبيعات',
                'chat' => ['id' => 900],
                'from' => ['id' => 77, 'username' => 'demo_user'],
            ],
        ]);

        $job->handle($assistant, $formatter, $telegram);

        $this->assertTrue(true);
    }
    public function test_it_sends_printed_documents_to_telegram_as_a_file(): void
    {
        $assistant = Mockery::mock(AssistantOrchestratorService::class);
        $telegram = Mockery::mock(TelegramBotService::class);
        $formatter = new TelegramAssistantResponseFormatter();
        $user = new User(['locale' => 'ar']);

        $message = new AssistantMessage([
            'assistant_message' => 'الفاتورة جاهزة للطباعة PDF.',
            'execution_result_json' => [
                'status' => 'completed',
                'summary' => 'الفاتورة جاهزة للطباعة PDF.',
                'data' => [
                    'print_document' => [
                        'filename' => 'invoice-15.pdf',
                        'download_url' => 'https://api.example.com/assistant/print/invoice/15/invoice-15.pdf?signature=test',
                        'telegram_document_url' => 'https://api.example.com/assistant/print/invoice/15/invoice-15.pdf?signature=test',
                    ],
                ],
            ],
        ]);

        $assistant->shouldReceive('getLinkedUserByTelegram')
            ->once()
            ->with(5, '77')
            ->andReturn($user);

        $assistant->shouldReceive('processMessage')
            ->once()
            ->with($user, 'اطبع الفاتورة 15', 'telegram')
            ->andReturn(['message' => $message]);

        $telegram->shouldReceive('sendDocument')
            ->once()
            ->withArgs(function (int $tenantId, string $chatId, string $url, ?string $caption): bool {
                return $tenantId === 5
                    && $chatId === '900'
                    && str_contains($url, '/assistant/print/invoice/15/invoice-15.pdf')
                    && $caption === 'الفاتورة جاهزة للطباعة PDF.';
            })
            ->andReturn(true);

        $telegram->shouldNotReceive('sendMessage');

        $job = new ProcessTelegramUpdateJob(5, [
            'message' => [
                'text' => 'اطبع الفاتورة 15',
                'chat' => ['id' => 900],
                'from' => ['id' => 77, 'username' => 'demo_user'],
            ],
        ]);

        $job->handle($assistant, $formatter, $telegram);

        $this->assertTrue(true);
    }
}
