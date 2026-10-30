<?php

use App\Http\Controllers\Api\ConnectionController;
use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Api\EmailEventController;
use App\Http\Controllers\Api\EmailRecoveryController;
use App\Http\Controllers\Api\SuppressionController;
use App\Http\Controllers\Webhooks\CloudflareInboundController;
use App\Http\Controllers\Webhooks\SesWebhookController;
use App\Http\Middleware\AuthenticateLarasendApiKey;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:larasend-api-auth', AuthenticateLarasendApiKey::class, 'throttle:larasend-api'])->group(function () {
    Route::get('connection', ConnectionController::class)->name('api.connection.show');
    Route::get('emails', [EmailController::class, 'index'])->name('api.emails.index');
    Route::post('emails', [EmailController::class, 'store'])->name('api.emails.store');
    Route::get('emails/lookup', EmailRecoveryController::class)->name('api.emails.lookup');
    Route::get('emails/{email}/events', EmailEventController::class)->name('api.emails.events.index');
    Route::get('emails/{email}', [EmailController::class, 'show'])->name('api.emails.show');
    Route::delete('suppressions/{suppression}', [SuppressionController::class, 'destroy'])->whereNumber('suppression')->name('api.suppressions.destroy');
});

Route::post('webhooks/ses/{token}', SesWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.ses');

Route::post('webhooks/inbound/cloudflare/{token}', CloudflareInboundController::class)
    ->middleware('throttle:240,1')
    ->name('webhooks.inbound.cloudflare');
