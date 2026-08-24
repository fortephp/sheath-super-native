<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Tests\Fixtures;

use Native\Mobile\Edge\Elements\Column;
use Override;

class ReflectedDynamicAttributesFixture extends Column
{
    /** @param array<string, mixed> $attrs */
    #[Override]
    public function applyAttributes(array $attrs): void
    {
        foreach (['foo'] as $key) {
            if (isset($attrs[$key])) {
                return;
            }
        }
    }
}
