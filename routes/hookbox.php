<?php

declare(strict_types=1);

use Hookbox\Http\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix((string) config('hookbox.route_prefix', 'webhooks'))
    ->post('{source}', WebhookController::class)
    ->name('hookbox.receive');
