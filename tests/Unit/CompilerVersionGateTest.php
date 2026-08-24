<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Tests\Unit;

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Linter;
use Forte\Sheath\NativePhp\Tests\Fixtures\CompilerGateProbeRule;
use Forte\Sheath\Packages\Dependencies;
use Forte\Sheath\Rules\RuleRegistry;

function compilerGateRuns(?Dependencies $dependencies): bool
{
    CompilerGateProbeRule::$runs = null;

    $rule = new CompilerGateProbeRule;
    $registry = new RuleRegistry;
    $registry->register($rule);

    $config = Config::make()->setRule($rule->getId(), ['severity' => 'error']);

    new Linter($registry, $dependencies)->lint(
        '<column><text>x</text></column>',
        'resources/views/native/gate.blade.php',
        $config
    );

    expect(CompilerGateProbeRule::$runs)->not->toBeNull();

    return (bool) CompilerGateProbeRule::$runs;
}

function lockedMobile(string $version): Dependencies
{
    return Dependencies::fromData(
        ['require' => ['nativephp/mobile' => '^4.0']],
        ['packages' => [['name' => 'nativephp/mobile', 'version' => $version]]]
    );
}

function constraintOnlyMobile(string $constraint): Dependencies
{
    return Dependencies::fromData(['require' => ['nativephp/mobile' => $constraint]]);
}

it('stands down on a provably pre-4 locked version', function (): void {
    expect(compilerGateRuns(lockedMobile('v3.9.9')))->toBeFalse();
});

it('runs on locked 4.x versions, pre-releases included', function (): void {
    expect(compilerGateRuns(lockedMobile('v4.0.0')))->toBeTrue()
        ->and(compilerGateRuns(lockedMobile('4.1.0-beta')))->toBeTrue();
});

it('stands down on a future major whose premises are unverified', function (): void {
    expect(compilerGateRuns(lockedMobile('v5.0.0')))->toBeFalse();
});

it('runs on dev branches', function (): void {
    expect(compilerGateRuns(lockedMobile('dev-instrumental-runtime')))->toBeTrue();
});

it('runs on lock-less constraint-only checkouts', function (): void {
    expect(compilerGateRuns(constraintOnlyMobile('^4.0')))->toBeTrue()
        ->and(compilerGateRuns(constraintOnlyMobile('~4.1')))->toBeTrue()
        ->and(compilerGateRuns(constraintOnlyMobile('*')))->toBeTrue();
});

it('runs when the package or the composer data is absent', function (): void {
    expect(compilerGateRuns(Dependencies::fromData(['require' => []])))->toBeTrue()
        ->and(compilerGateRuns(null))->toBeTrue();
});
