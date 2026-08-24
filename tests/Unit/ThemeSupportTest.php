<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Tests\Unit;

use Forte\Sheath\NativePhp\Support\RuntimeContext;
use Forte\Sheath\NativePhp\Support\TailwindOracle;
use Forte\Sheath\NativePhp\Support\Theme\ThemeClass;
use Forte\Sheath\NativePhp\Support\Theme\ThemeTokenSource;
use Forte\Sheath\NativePhp\Support\Theme\ThemeTokenStatus;
use Native\Mobile\Edge\TailwindParser;

afterEach(function (): void {
    TailwindParser::setThemeResolver(null);
    TailwindParser::setThemeDarkResolver(null);
    TailwindOracle::reset();
});

it('classifies native theme and fixed-color utilities', function (): void {
    $theme = ThemeClass::parse('ios:dark:bg-theme-on-surface/15');
    $fixed = ThemeClass::parse('android:border-[#AABBCC]/50');

    expect($theme)
        ->not->toBeNull()
        ->and($theme?->token)->toBe('on-surface')
        ->and($theme?->fixedColor)->toBeFalse()
        ->and($fixed)
        ->not->toBeNull()
        ->and($fixed?->token)->toBeNull()
        ->and($fixed?->fixedColor)->toBeTrue()
        ->and(ThemeClass::parse('bg-red-500'))->toBeNull()
        ->and(ThemeClass::parse('shadow-[#000000]'))->toBeNull();
});

it('resolves configured theme tokens without a runtime resolver', function (): void {
    config()->set('native-ui.theme', [
        'light' => ['surface' => '#FFFFFF'],
        'dark' => ['night-only' => '#000000'],
    ]);
    TailwindParser::setThemeResolver(null);
    TailwindParser::setThemeDarkResolver(null);

    $source = ThemeTokenSource::current();

    expect($source->canVerify())->toBeTrue()
        ->and($source->tokens())->toBe(['surface'])
        ->and($source->status('bg-theme-surface', 'surface'))->toBe(ThemeTokenStatus::Resolved)
        ->and($source->status('bg-theme-night-only', 'night-only'))->toBe(ThemeTokenStatus::UndefinedConfigured);
});

it('caches repeated runtime theme probes', function (): void {
    config()->set('native-ui.theme');
    $calls = 0;
    TailwindParser::setThemeResolver(static function (string $token) use (&$calls): ?string {
        $calls++;

        return $token === 'runtime' ? '#123456' : null;
    });
    TailwindOracle::reset();

    $source = ThemeTokenSource::current();

    expect($source->status('bg-theme-runtime', 'runtime'))->toBe(ThemeTokenStatus::Resolved);
    $afterFirstProbe = $calls;

    expect($afterFirstProbe)->toBeGreaterThan(0)
        ->and($source->status('bg-theme-runtime', 'runtime'))->toBe(ThemeTokenStatus::Resolved)
        ->and($calls)->toBe($afterFirstProbe);
});

it('normalizes configured theme data for stable cache fingerprints', function (): void {
    config()->set('native-ui.theme', [
        'dark' => ['surface' => '#000000'],
        'light' => ['primary' => '#6750A4', 'surface' => '#FFFFFF'],
    ]);
    $first = ThemeTokenSource::configurationFingerprint();

    config()->set('native-ui.theme', [
        'light' => ['surface' => '#FFFFFF', 'primary' => '#6750A4'],
        'dark' => ['surface' => '#000000'],
    ]);

    expect(ThemeTokenSource::configurationFingerprint())->toBe($first);
});

it('invalidates runtime cache context when the active theme resolver changes', function (): void {
    config()->set('native-ui.theme');
    TailwindParser::setThemeResolver(null);
    TailwindParser::setThemeDarkResolver(null);
    $withoutResolver = RuntimeContext::fingerprint();

    TailwindParser::setThemeResolver(static fn (string $token): ?string => $token === 'brand' ? '#123456' : null);
    $withResolver = RuntimeContext::fingerprint();

    expect(serialize($withoutResolver))->not->toBe(serialize($withResolver))
        ->and(ThemeTokenSource::current()->status('bg-theme-brand', 'brand'))
        ->toBe(ThemeTokenStatus::Resolved);
});
