<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Assistant\AssistantPdfService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AssistantPrintController extends Controller
{
    public function __construct(
        private readonly AssistantPdfService $pdfService,
    ) {}

    public function download(Request $request, string $type, int $record, string $filename): Response
    {
        $tenantId = (int) $request->query('tenant');
        $userId = (int) $request->query('user');

        $user = User::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($userId);

        return $this->pdfService->download($user, $type, $record, $filename);
    }
}
