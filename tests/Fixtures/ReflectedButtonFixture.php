<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Tests\Fixtures;

use Native\Mobile\Edge\Elements\Column;
use Override;

class ReflectedButtonFixture extends Column
{
    /** @param array<string, mixed> $attrs */
    #[Override]
    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['label']) || isset($attrs['icon']) || isset($attrs['a11y-label'])) {
            return;
        }
    }
}
