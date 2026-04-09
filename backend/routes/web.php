<?php

use App\Http\Controllers\AssistantPrintController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/assistant/print/{type}/{record}/{filename}', [AssistantPrintController::class, 'download'])
    ->middleware('signed')
    ->name('assistant.print.download');
