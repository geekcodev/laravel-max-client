<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Integration;

use GeekCo\LaravelMaxClient\MaxServiceProvider;
use GeekCo\LaravelMaxClient\Tests\TestCase;
use GeekCo\MaxPhpClient\ApiClient;
use GeekCo\MaxPhpClient\Exception\NetworkException;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class SmokeTest extends TestCase
{
    public function testGetMeAgainstRealApi(): void
    {
        $token = (string) env('MAX_API_TOKEN', '');
        if ($token === '') {
            self::markTestSkipped('MAX_API_TOKEN is not set.');
        }

        $this->app['config']->set(MaxServiceProvider::CONFIG_KEY . '.api_token', $token);

        $caFile = __DIR__ . '/../Fixtures/max-ca-chain.pem';
        if (is_file($caFile)) {
            $this->app['config']->set(
                MaxServiceProvider::CONFIG_KEY . '.http.options.verify',
                $caFile,
            );
        }

        $me = $this->probe(fn (): mixed => $this->app->make(ApiClient::class)->getMe());

        $this->assertTrue($me->isBot);
        $this->assertNotSame('', $me->username);
    }

    /**
     * @template T
     *
     * @param callable(): T $callable
     *
     * @return T
     */
    private function probe(callable $callable): mixed
    {
        try {
            return $callable();
        } catch (NetworkException $e) {
            self::markTestSkipped('MAX API unreachable from test environment: ' . $e->getMessage());
        }
    }
}
