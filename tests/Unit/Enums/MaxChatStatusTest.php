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

    public function testLabelsFollowLocale(): void
    {
        app()->setLocale('ru');

        $this->assertSame('Активен', MaxChatStatus::Active->label());
        $this->assertSame('Остановлен', MaxChatStatus::Stopped->label());
        $this->assertSame('Удалён', MaxChatStatus::Removed->label());

        app()->setLocale('en');

        $this->assertSame('Active', MaxChatStatus::Active->label());
        $this->assertSame('Stopped', MaxChatStatus::Stopped->label());
        $this->assertSame('Removed', MaxChatStatus::Removed->label());
    }
}
