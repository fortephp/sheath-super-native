<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Tests\Fixtures;

use Native\Mobile\Edge\Elements\Column;

class ReflectedEventFixture extends Column
{
    public function onChange(string $method): static
    {
        return $this;
    }

    public function onEndReached(string $method): static
    {
        return $this;
    }
}
