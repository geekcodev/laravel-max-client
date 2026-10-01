<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Support;

use GeekCo\LaravelMaxClient\Tests\TestCase;

/**
 * Совместимость уровня переменной окружения для интервала перепроверки
 * метаданных чата.
 *
 * Фолбэк живёт в конфиге пакета, а не в Support\Config: читать env() можно
 * только на этапе конфига, иначе после config:cache значение не доедет. Поэтому
 * проверяется сам файл конфига, а не поведение уже собранного приложения.
 */
final class ConfigEnvCompatibilityTest extends TestCase
{
    private const LEGACY = 'MAX_CHATS_TITLE_CHECK_INTERVAL';
    private const CURRENT = 'MAX_CHATS_CHAT_CHECK_INTERVAL';

    protected function tearDown(): void
    {
        foreach ([self::LEGACY, self::CURRENT] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }

        parent::tearDown();
    }

    /**
     * Прежнее имя действовало в v1.1.4: у установки, которая его так и не
     * переименовала, интервал должен продолжать применяться.
     */
    public function testLegacyEnvVariableStillApplies(): void
    {
        $this->setEnv(self::LEGACY, '3600');

        $this->assertSame(3600, $this->chatsConfig()['chat_check_interval']);
    }

    public function testCurrentEnvVariableApplies(): void
    {
        $this->setEnv(self::CURRENT, '600');

        $this->assertSame(600, $this->chatsConfig()['chat_check_interval']);
    }

    public function testCurrentEnvVariableWinsOverLegacy(): void
    {
        $this->setEnv(self::LEGACY, '3600');
        $this->setEnv(self::CURRENT, '600');

        $this->assertSame(600, $this->chatsConfig()['chat_check_interval']);
    }

    /**
     * .env.example оставляет переменную пустой, поэтому пустая строка — это «не
     * задано», а не ноль: иначе обновление пакета молча обнуляло бы заданный
     * прежним именем интервал.
     */
    public function testEmptyCurrentEnvVariableFallsBackToLegacy(): void
    {
        $this->setEnv(self::LEGACY, '3600');
        $this->setEnv(self::CURRENT, '');

        $this->assertSame(3600, $this->chatsConfig()['chat_check_interval']);
    }

    /**
     * Явный ноль — это значение («проверять всегда»), и он важнее прежнего
     * имени: без этой разницы переопределить старый интервал было бы нечем.
     */
    public function testExplicitZeroInCurrentEnvVariableWinsOverLegacy(): void
    {
        $this->setEnv(self::LEGACY, '3600');
        $this->setEnv(self::CURRENT, '0');

        $this->assertSame(0, $this->chatsConfig()['chat_check_interval']);
    }

    public function testIntervalIsZeroWhenNeitherVariableIsSet(): void
    {
        $this->assertSame(0, $this->chatsConfig()['chat_check_interval']);
    }

    /**
     * @return array<string, mixed>
     */
    private function chatsConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../../../config/laravel-max-client.php';

        return (array) $config['chats'];
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
