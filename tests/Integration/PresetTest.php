<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Tests\Integration;

use Forte\Sheath\Configuration\DefaultConfigFactory;
use Forte\Sheath\Configuration\PackagePresets;
use Forte\Sheath\SheathManager;

afterEach(function (): void {
    PackagePresets::reset();
});

it('registers the nativephp preset through the provider', function (): void {
    config()->set('sheath.packageRequirementMode', 'ignore');

    $sheath = app(SheathManager::class);

    $config = DefaultConfigFactory::resolve(
        ['preset' => ['empty', 'nativephp'], 'packageRequirementMode' => 'ignore'],
        $sheath->getRuleRegistry()
    );

    $nativeRuleIds = array_values(array_filter(
        array_keys($config->getRules()),
        static fn (string $id): bool => str_starts_with($id, 'native-')
    ));
    $registeredNativeRuleIds = array_values(array_filter(
        $sheath->getRuleRegistry()->all(),
        static fn (string $id): bool => str_starts_with($id, 'native-')
    ));

    expect(array_diff($nativeRuleIds, $registeredNativeRuleIds))->toBe([])
        ->and(array_diff($registeredNativeRuleIds, $nativeRuleIds))->toBe([])
        ->and($config->getRules()['native-unknown-element'])->toBe('error')
        ->and($config->getRules()['native-prefer-theme-tokens'])->toBe('info');
});

it('composes the nativephp preset with recommended', function (): void {
    config()->set('sheath.packageRequirementMode', 'ignore');

    $sheath = app(SheathManager::class);

    $config = DefaultConfigFactory::resolve(
        ['preset' => ['recommended', 'nativephp'], 'packageRequirementMode' => 'ignore'],
        $sheath->getRuleRegistry()
    );

    expect($config->getRules())->toHaveKey('security-csrf-field')
        ->and($config->getRules())->toHaveKey('native-dead-class')
        ->and($config->getRuleExclusions('best-practices-no-inline-styles'))->toContain('views/native/');
});
