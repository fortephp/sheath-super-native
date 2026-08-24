<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Tests;

use Forte\Sheath\NativePhp\ServiceProvider;
use Forte\Sheath\ServiceProvider as SheathServiceProvider;
use Forte\Sheath\Testing\RuleTester;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            SheathServiceProvider::class,
            ServiceProvider::class,
        ];
    }

    protected function getRuleTester(): RuleTester
    {
        return new RuleTester;
    }
}
