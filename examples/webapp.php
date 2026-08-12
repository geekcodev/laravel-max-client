<?php

declare(strict_types=1);

// Верификация стартовых данных мини-приложения (middleware max.webapp).
//
// MAX открывает мини-приложение по URL вида https://<app>/webapp?WebAppData=...
// Без проверки подписи любой может подделать user_id/chat_id, поэтому данные
// ОБЯЗАТЕЛЬНО верифицируются (HMAC-SHA256, ядро WebAppDataValidator).
// Готовый middleware max.webapp делает это за вас: кладёт user_id/chat_id в
// сессию и атрибут запроса, при MAX_WEBAPP_STRICT=true отвечает 403 без
// валидных данных (иначе — пропускает в демо-режиме). max.csp добавляет
// frame-ancestors для встраивания в MAX.

namespace App\Http\Controllers;

use GeekCo\LaravelMaxClient\WebApp\ResolveWebAppIdentity;
use Illuminate\Http\Request;
use Illuminate\View\View;

// routes/web.php:
//
//   Route::get('/webapp', WebAppController::class)
//       ->middleware(['max.webapp', 'max.csp']);

final class WebAppController
{
    public function __invoke(Request $request): View
    {
        // Верифицированная идентичность из атрибута запроса (WebAppIdentity|null).
        $identity = $request->attributes->get(ResolveWebAppIdentity::REQUEST_ATTRIBUTE);

        // $identity->userId и $identity->chatId — int64|null. В строгом режиме
        // middleware уже вернул 403, поэтому здесь $identity не null.
        $request->session()->get('user_id');
        $request->session()->get('chat_id');

        return view('webapp', [
            'user_id' => $identity?->userId,
            'chat_id' => $identity?->chatId,
        ]);
    }
}
