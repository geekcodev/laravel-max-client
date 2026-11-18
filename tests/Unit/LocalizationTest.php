<?php

declare(strict_types=1);

namespace GeekCo\LaravelMaxClient\Tests\Unit;

use GeekCo\LaravelMaxClient\Tests\TestCase;

final class LocalizationTest extends TestCase
{
    public function testRuAndEnLangFilesHaveSameKeys(): void
    {
        foreach (['chat', 'enums'] as $file) {
            $ru = $this->flatten(require __DIR__.'/../../lang/ru/'.$file.'.php');
            $en = $this->flatten(require __DIR__.'/../../lang/en/'.$file.'.php');

            self::assertSame(
                array_keys($ru),
                array_keys($en),
                'Language keys differ for '.$file.'.php',
            );
        }
    }

    /**
     * @param array<array-key, mixed> $messages
     *
     * @return array<string, string>
     */
    private function flatten(array $messages, string $prefix = ''): array
    {
        $flattened = [];

        foreach ($messages as $key => $value) {
            if (\is_array($value)) {
                $flattened += $this->flatten($value, $prefix.$key.'.');

                continue;
            }

            $flattened[$prefix.$key] = (string) $value;
        }

        \ksort($flattened);

        return $flattened;
    }
}
