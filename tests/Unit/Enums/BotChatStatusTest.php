<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Enums;

use GeekCo\LaravelMaxClient\Enums\BotChatStatus;
use GeekCo\LaravelMaxClient\Tests\TestCase;

final class BotChatStatusTest extends TestCase
{
    public function testValues(): void
    {
        $this->assertSame('active', BotChatStatus::Active->value);
        $this->assertSame('stopped', BotChatStatus::Stopped->value);
        $this->assertSame('removed', BotChatStatus::Removed->value);
    }

    public function testLabels(): void
    {
        $this->assertSame('Активен', BotChatStatus::Active->label());
        $this->assertSame('Остановлен', BotChatStatus::Stopped->label());
        $this->assertSame('Удалён', BotChatStatus::Removed->label());
    }
}
