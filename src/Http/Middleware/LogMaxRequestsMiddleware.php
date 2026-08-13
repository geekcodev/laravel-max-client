<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Http\Middleware;

use Closure;
use GeekCo\LaravelMaxClient\Support\Config;
use GeekCo\LaravelMaxClient\Support\Logger;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

final class LogMaxRequestsMiddleware
{
    public function __construct(
        private readonly Logger $logger,
        private readonly Config $config,
    ) {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $log = $this->resolveLogger($request);

        if ($log !== null) {
            $this->logRequest($log, $request);
        }

        $start = microtime(true);
        $response = $next($request);

        $requestId = $request->header('X-Request-ID');
        if (is_string($requestId)) {
            $response->headers->set('X-Request-ID', $requestId);
        }

        if ($log !== null) {
            $this->logResponse($log, $request, $response, (microtime(true) - $start) * 1000);
        }

        return $response;
    }

    private function resolveLogger(Request $request): ?LoggerInterface
    {
        if (! $this->logger->isEnabled()) {
            return null;
        }

        if ($this->isPathExcluded($request, $this->config->loggingExcludePaths())) {
            return null;
        }

        return $this->logger->logger();
    }

    /**
     * @param list<string> $excludePaths
     */
    private function isPathExcluded(Request $request, array $excludePaths): bool
    {
        if ($excludePaths === []) {
            return false;
        }

        $path = '/'.trim($request->path(), '/').'/';

        foreach ($excludePaths as $excluded) {
            $search = '/'.trim($excluded, '/').'/';

            if (str_contains($path, $search)) {
                return true;
            }
        }

        return false;
    }

    private function logRequest(LoggerInterface $logger, Request $request): void
    {
        $data = [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];

        $shouldLogBody = $this->config->loggingLogRequestBody()
            && ! $this->isPathExcluded($request, $this->config->loggingExcludeRequestBodyPaths());

        if ($shouldLogBody) {
            $body = $request->getContent();

            if ($body !== '') {
                $decoded = json_decode($body, true);

                $data['body'] = is_array($decoded)
                    ? $this->maskSensitiveData($decoded)
                    : ($decoded ?? $body);
            }
        }

        $logger->info('Incoming MAX request', $data);
    }

    private function logResponse(LoggerInterface $logger, Request $request, Response $response, float $durationMs): void
    {
        $data = [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'status' => $response->getStatusCode(),
            'duration_ms' => round($durationMs, 2),
        ];

        $shouldLogBody = $this->config->loggingLogResponseBody()
            && ! $this->isPathExcluded($request, $this->config->loggingExcludeResponseBodyPaths());

        if ($shouldLogBody) {
            $content = $response->getContent();

            if ($content !== '' && $content !== false) {
                $decoded = json_decode($content, true);

                $data['body'] = is_array($decoded)
                    ? $this->maskSensitiveData($decoded)
                    : ($decoded ?? mb_substr(
                        $content,
                        0,
                        $this->config->loggingLogResponseBodyMaxLength(),
                    ));
            }
        }

        $level = $response->isSuccessful() ? 'info' : ($response->isClientError() ? 'warning' : 'error');

        $logger->log($level, 'MAX response', $data);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private function maskSensitiveData(array $data): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'auth_token', 'access_token', 'api_key', 'authorization'];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(mb_strtolower($key), $sensitiveKeys, true)) {
                $data[$key] = '***';
            } elseif (is_array($value)) {
                $data[$key] = $this->maskSensitiveData($value);
            }
        }

        return $data;
    }
}
