<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Http;

use GeekCo\LaravelMaxClient\Support\Config;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;

final class HttpClientFactory
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly Config $config,
    ) {
    }

    public function createClient(): ClientInterface
    {
        if ($this->container->has(ClientInterface::class)) {
            $client = $this->container->get(ClientInterface::class);
            if (!$client instanceof ClientInterface) {
                throw new \RuntimeException(
                    sprintf('The container binding for "%s" must implement PSR-18.', ClientInterface::class),
                );
            }

            return $client;
        }

        return new GuzzleClient($this->config->httpOptions());
    }

    public function createHttpFactory(): HttpFactory
    {
        return new HttpFactory();
    }
}
