<?php

declare(strict_types=1);

// Верификация стартовых данных мини-приложения (WebAppContext).
//
// MAX открывает мини-приложение по URL вида https://<app>/webapp?WebAppData=...
// Без проверки подписи любой может подделать user_id/chat_id, поэтому данные
// ОБЯЗАТЕЛЬНО верифицируются через WebAppContext (HMAC-SHA256, ядро
// WebAppDataValidator). Свежесть auth_date проверяется по MAX_WEBAPP_MAX_AGE.

namespace App\Http\Controllers;

use GeekCo\LaravelMaxClient\WebApp\WebAppContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class WebAppController
{
    public function __invoke(Request $request, WebAppContext $webAppContext): View
    {
        $identity = $webAppContext->resolve($request);

        if ($identity === null) {
            abort(403, 'Невалидные данные мини-приложения.');
        }

        // $identity->userId и $identity->chatId — int64|null (из верифицированных данных).
        $request->session()->put([
            'user_id' => $identity->userId,
            'chat_id' => $identity->chatId,
        ]);

        return view('webapp', [
            'user_id' => $identity->userId,
            'chat_id' => $identity->chatId,
        ]);
    }
}
