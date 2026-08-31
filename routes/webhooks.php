<?php

declare(strict_types=1);

use App\Http\Controllers\Webhooks\MetaWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Provider webhooks (spec §52)
|--------------------------------------------------------------------------
|
| Deliberately outside the `web` group: these requests carry no session and
| no CSRF token, and should not be given one. The signature on the body is
| what authenticates them, and it is checked before anything is read.
|
| Rate limited because the URL is public. A provider's normal volume is far
| below this; anything above it is not a provider.
|
*/

Route::middleware('throttle:120,1')->group(function (): void {
    Route::get('meta', [MetaWebhookController::class, 'verify'])->name('webhooks.meta.verify');
    Route::post('meta', [MetaWebhookController::class, 'receive'])->name('webhooks.meta.receive');
});
