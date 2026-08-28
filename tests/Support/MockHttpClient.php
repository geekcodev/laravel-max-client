<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class MockHttpClient implements ClientInterface
{
    public ?RequestInterface $lastRequest = null;

    /**
     * Все запросы в порядке отправки (для проверки батчинга и т.п.).
     *
     * @var list<RequestInterface>
     */
    public array $requests = [];

    public int $callCount = 0;

    /**
     * @var list<ResponseInterface>
     */
    private array $responses = [];

    private int $index = 0;

    /**
     * @param list<ResponseInterface> $responses
     */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    public function queue(ResponseInterface $response): void
    {
        $this->responses[] = $response;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;
        $this->requests[] = $request;
        ++$this->callCount;

        $response = $this->responses[$this->index] ?? null;
        if (!$response instanceof ResponseInterface) {
            return new Response(200, [], '{}');
        }

        ++$this->index;

        return $response;
    }
}
