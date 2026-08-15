<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient;

use GeekCo\LaravelMaxClient\Console\MaxListenCommand;
use GeekCo\LaravelMaxClient\Console\MaxListSubscriptionsCommand;
use GeekCo\LaravelMaxClient\Console\MaxSubscribeCommand;
use GeekCo\LaravelMaxClient\Console\MaxUnsubscribeCommand;
use GeekCo\LaravelMaxClient\Http\HttpClientFactory;
use GeekCo\LaravelMaxClient\Http\Middleware\LogMaxRequestsMiddleware;
use GeekCo\LaravelMaxClient\Http\Middleware\SetMaxFrameAncestors;
use GeekCo\LaravelMaxClient\Listeners\PersistBotChatListener;
use GeekCo\LaravelMaxClient\Support\Config;
use GeekCo\LaravelMaxClient\Support\Logger;
use GeekCo\LaravelMaxClient\WebApp\ResolveWebAppIdentity;
use GeekCo\LaravelMaxClient\WebApp\WebAppContext;
use GeekCo\LaravelMaxClient\Webhook\HandleMaxUpdateJob;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use GeekCo\LaravelMaxClient\Webhook\MaxWebhookController;
use GeekCo\LaravelMaxClient\Webhook\VerifyMaxWebhookSecret;
use GeekCo\MaxPhpClient\ApiClient;
use GeekCo\MaxPhpClient\Dto\Update;
use GeekCo\MaxPhpClient\LongPolling\LongPollingRunner;
use GeekCo\MaxPhpClient\RateLimit\RateLimiter;
use GeekCo\MaxPhpClient\Retry\RetryStrategy;
use GeekCo\MaxPhpClient\Security\WebAppDataValidator;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

final class MaxServiceProvider extends ServiceProvider
{
    public const CONFIG_KEY = 'laravel-max-client';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/laravel-max-client.php', self::CONFIG_KEY);

        $this->app->singleton(
            Config::class,
            static fn (): Config => new Config(),
        );

        $this->app->singleton(
            Logger::class,
            static fn (Container $app): Logger => new Logger($app->make(Config::class)),
        );

        $this->app->singleton(
            HttpClientFactory::class,
            static fn (Container $app): HttpClientFactory => new HttpClientFactory($app, $app->make(Config::class)),
        );

        $this->app->singleton(
            WebAppDataValidator::class,
            static fn (Container $app): WebAppDataValidator => new WebAppDataValidator(
                accessToken: $app->make(Config::class)->apiToken(),
                maxAge: $app->make(Config::class)->webappMaxAge(),
            ),
        );

        $this->app->singleton(
            WebAppContext::class,
            static fn (Container $app): WebAppContext => new WebAppContext($app->make(WebAppDataValidator::class)),
        );

        $this->app->singleton(ApiClient::class, static function (Container $app): ApiClient {
            $config = $app->make(Config::class);
            $httpFactory = $app->make(HttpClientFactory::class);

            return ApiClient::create(
                httpClient: $httpFactory->createClient(),
                requestFactory: $httpFactory->createHttpFactory(),
                streamFactory: $httpFactory->createHttpFactory(),
                uriFactory: $httpFactory->createHttpFactory(),
                accessToken: $config->apiToken(),
                baseUri: $config->baseUri(),
                retryStrategy: new RetryStrategy(
                    maxAttempts: $config->retryMaxAttempts(),
                    baseDelaySeconds: $config->retryBaseDelaySeconds(),
                    maxDelaySeconds: $config->retryMaxDelaySeconds(),
                    factor: $config->retryFactor(),
                    retryOnNonIdempotent: $config->retryOnNonIdempotent(),
                ),
                rateLimiter: new RateLimiter(
                    tokensPerSecond: $config->rateLimitTokensPerSecond(),
                    maxTokens: $config->rateLimitMaxTokens(),
                ),
                globalRateLimiter: new RateLimiter(
                    tokensPerSecond: $config->globalRateLimitTokensPerSecond(),
                    maxTokens: $config->globalRateLimitMaxTokens(),
                ),
            );
        });

        $this->app->singleton(LongPollingRunner::class, static function (Container $app): LongPollingRunner {
            $config = $app->make(Config::class);

            return new LongPollingRunner(
                api: $app->make(ApiClient::class),
                handler: static function (Update $update) use ($app, $config): bool {
                    if ($app->make(Dispatcher::class)->hasListeners(MaxUpdateReceived::class)) {
                        HandleMaxUpdateJob::dispatch($update)->onQueue($config->webhookQueue());
                    }

                    return true;
                },
                limit: $config->pollingLimit(),
                timeout: $config->pollingTimeout(),
                breakOnFailure: $config->pollingBreakOnFailure(),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MaxListenCommand::class,
                MaxListSubscriptionsCommand::class,
                MaxSubscribeCommand::class,
                MaxUnsubscribeCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/laravel-max-client.php' => $this->app->configPath(self::CONFIG_KEY.'.php'),
            ], self::CONFIG_KEY.'-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], self::CONFIG_KEY.'-migrations');
        }

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('max.webapp', ResolveWebAppIdentity::class);
        $router->aliasMiddleware('max.csp', SetMaxFrameAncestors::class);
        $router->aliasMiddleware('max.log', LogMaxRequestsMiddleware::class);

        $config = $this->app->make(Config::class);
        if ($config->webhookEnabled() && $config->webhookSecret() !== null) {
            $middleware = [VerifyMaxWebhookSecret::class, ...$config->webhookMiddleware()];

            if ($config->loggingEnabled()) {
                array_unshift($middleware, LogMaxRequestsMiddleware::class);
            }

            $router
                ->post($config->webhookPath(), MaxWebhookController::class)
                ->middleware($middleware)
                ->name('max.webhook');
        }

        if ($config->chatsEnabled()) {
            $this->app->make(Dispatcher::class)->listen(
                MaxUpdateReceived::class,
                PersistBotChatListener::class,
            );
        }
    }
}
