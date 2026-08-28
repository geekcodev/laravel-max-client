<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit\Enums;

use GeekCo\LaravelMaxClient\Enums\MaxChatStatus;
use GeekCo\LaravelMaxClient\Tests\TestCase;

final class MaxChatStatusTest extends TestCase
{
    public function testValues(): void
    {
        $this->assertSame('active', MaxChatStatus::Active->value);
        $this->assertSame('stopped', MaxChatStatus::Stopped->value);
        $this->assertSame('removed', MaxChatStatus::Removed->value);
    }

    public function testLabels(): void
    {
        $this->assertSame('Активен', MaxChatStatus::Active->label());
        $this->assertSame('Остановлен', MaxChatStatus::Stopped->label());
        $this->assertSame('Удалён', MaxChatStatus::Removed->label());
    }
}
