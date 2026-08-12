<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient;

use GeekCo\LaravelMaxClient\Console\MaxListenCommand;
use GeekCo\LaravelMaxClient\Console\MaxListSubscriptionsCommand;
use GeekCo\LaravelMaxClient\Console\MaxSubscribeCommand;
use GeekCo\LaravelMaxClient\Console\MaxUnsubscribeCommand;
use GeekCo\LaravelMaxClient\Http\HttpClientFactory;
use GeekCo\LaravelMaxClient\Support\Config;
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
                __DIR__ . '/../config/laravel-max-client.php' => $this->app->configPath(self::CONFIG_KEY . '.php'),
            ], self::CONFIG_KEY . '-config');
        }

        $config = $this->app->make(Config::class);
        if ($config->webhookEnabled() && $config->webhookSecret() !== null) {
            $this->app->make(Router::class)
                ->post($config->webhookPath(), MaxWebhookController::class)
                ->middleware([VerifyMaxWebhookSecret::class, ...$config->webhookMiddleware()])
                ->name('max.webhook');
        }
    }
}
