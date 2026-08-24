<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Tests\Integration;

use Forte\Sheath\NativePhp\Support\RuntimeContext;
use Forte\Sheath\NativePhp\Support\TailwindOracle;
use Native\Mobile\Edge\TailwindParser;
use Native\Mobile\Platform;
use ReflectionClass;
use RuntimeException;

function tailwindState(string $property): mixed
{
    return new ReflectionClass(TailwindParser::class)->getProperty($property)->getValue();
}

afterEach(function (): void {
    TailwindParser::setThemeResolver(null);
    TailwindParser::setThemeDarkResolver(null);
    TailwindParser::setPlatform(null);
    TailwindParser::clearCache();
});

it('restores application platform resolvers and parser caches after an oracle probe', function (): void {
    $light = static fn (string $token): ?string => $token === 'brand' ? '#123456' : null;
    $dark = static fn (string $token): ?string => $token === 'brand' ? '#654321' : null;

    TailwindParser::setThemeResolver($light);
    TailwindParser::setThemeDarkResolver($dark);
    TailwindParser::setPlatform('ios');
    TailwindParser::parse('p-8 bg-theme-brand');

    $cache = tailwindState('cache');
    $unsupported = tailwindState('unsupportedCache');

    expect(TailwindOracle::parseOn('android', 'p-[137]'))->toHaveKey('padding', 137.0)
        ->and(Platform::current())->toBe('ios')
        ->and(tailwindState('themeResolver'))->toBe($light)
        ->and(tailwindState('themeDarkResolver'))->toBe($dark)
        ->and(tailwindState('cache'))->toBe($cache)
        ->and(tailwindState('unsupportedCache'))->toBe($unsupported)
        ->and(TailwindParser::parse('bg-theme-sheath-unknown'))->toBe([]);
});

it('restores compiler state when a runtime theme probe throws', function (): void {
    $light = static fn (string $token): ?string => match ($token) {
        'brand' => '#123456',
        'explode' => throw new RuntimeException('probe failed'),
        default => null,
    };
    $dark = static fn (string $token): ?string => $token === 'brand' ? '#654321' : null;

    TailwindParser::setThemeResolver($light);
    TailwindParser::setThemeDarkResolver($dark);
    TailwindParser::setPlatform('ios');
    TailwindParser::parse('p-8');
    $cache = tailwindState('cache');

    expect(TailwindOracle::runtimeClassContributes('bg-theme-explode'))->toBeNull()
        ->and(Platform::current())->toBe('ios')
        ->and(tailwindState('themeResolver'))->toBe($light)
        ->and(tailwindState('themeDarkResolver'))->toBe($dark)
        ->and(tailwindState('cache'))->toBe($cache)
        ->and(TailwindParser::parse('bg-theme-sheath-unknown'))->toBe([]);
});

it('does not reuse a cache identity for mutable state captured by a resolver', function (): void {
    $state = (object) ['colors' => ['brand' => '#123456']];
    $resolver = static fn (string $token): ?string => $state->colors[$token] ?? null;

    TailwindParser::setThemeResolver($resolver);
    $before = RuntimeContext::fingerprint();
    expect(TailwindParser::parse('bg-theme-brand'))->not->toBe([]);

    $state->colors = [];
    TailwindParser::setThemeResolver($resolver);
    $after = RuntimeContext::fingerprint();

    expect(TailwindParser::parse('bg-theme-brand'))->toBe([])
        ->and($after)->not->toBe($before);
});

it('does not reuse a cache identity for mutable state on a closure binding', function (): void {
    $state = new class
    {
        /** @var array<string, string> */
        public array $colors = ['brand' => '#123456'];

        public function resolver(): \Closure
        {
            return fn (string $token): ?string => $this->colors[$token] ?? null;
        }
    };
    $resolver = $state->resolver();

    TailwindParser::setThemeResolver($resolver);
    $before = RuntimeContext::fingerprint();
    expect(TailwindParser::parse('bg-theme-brand'))->not->toBe([]);

    $state->colors = [];
    TailwindParser::setThemeResolver($resolver);
    TailwindParser::clearCache();
    $after = RuntimeContext::fingerprint();

    expect(TailwindParser::parse('bg-theme-brand'))->toBe([])
        ->and($after)->not->toBe($before);
});

it('preserves associative capture order in the resolver fingerprint', function (): void {
    $colors = ['primary' => '#123456', 'secondary' => '#654321'];
    $resolver = static function (string $token) use (&$colors): ?string {
        $color = array_values($colors)[0] ?? null;

        return $token === 'choice' ? $color : null;
    };

    TailwindParser::setThemeResolver($resolver);
    $before = RuntimeContext::fingerprint();
    $beforeColor = $resolver('choice');

    $colors = array_reverse($colors, true);
    TailwindParser::setThemeResolver($resolver);
    $after = RuntimeContext::fingerprint();

    expect($resolver('choice'))->not->toBe($beforeColor)
        ->and($after)->not->toBe($before);
});
