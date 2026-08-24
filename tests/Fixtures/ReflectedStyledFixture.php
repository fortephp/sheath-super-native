<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Tests\Fixtures;

use Native\Mobile\Edge\Elements\Column;
use Override;

class ReflectedStyledFixture extends Column
{
    /** @param array<string, mixed> $attrs */
    #[Override]
    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['style'])) {
            return;
        }
    }
}
