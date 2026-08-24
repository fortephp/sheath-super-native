<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Support;

use Native\Mobile\Edge\NativeTagPrecompiler;
use Throwable;

final class PrecompilerOracle
{
    /** @var array<string, bool> */
    private static array $cache = [];

    /** @var array<string, bool> */
    private static array $eventCache = [];

    /** @var list<string> */
    private const array COMPILED_DIRECTIVE_FALLBACK = [
        'tapDown', 'tapUp', 'tap', 'longTap', 'pressDown', 'pressUp', 'press',
        'longPress', 'doubleTap', 'selectionChange', 'change', 'submit',
        'dismiss', 'refresh', 'endReached', 'swipeDelete', 'swipe',
        'pinchEnd', 'navigated', 'navigate', 'model',
    ];

    public static function available(): bool
    {
        return class_exists(NativeTagPrecompiler::class);
    }

    public static function isCompiledAway(string $tag): bool
    {
        if (isset(self::$cache[$tag])) {
            return self::$cache[$tag];
        }

        if (! self::available()) {
            return self::$cache[$tag] = ElementCatalog::isKnownTag($tag);
        }

        try {
            $out = self::queryCompiler(
                ElementCatalog::shortFormTags(),
                static fn (NativeTagPrecompiler $compiler, bool $_supportsActivation): string => $compiler("<{$tag} data-probe=\"1\"></{$tag}>"),
            );

            return self::$cache[$tag] = ! str_contains($out, "<{$tag} data-probe");
        } catch (Throwable) {
            return self::$cache[$tag] = ElementCatalog::isKnownTag($tag);
        }
    }

    public static function isArbitraryEventBinding(string $name): bool
    {
        if (isset(self::$eventCache[$name])) {
            return self::$eventCache[$name];
        }

        if (! self::available()) {
            return self::$eventCache[$name] = ! in_array($name, self::COMPILED_DIRECTIVE_FALLBACK, true);
        }

        try {
            $out = self::queryCompiler(
                ['column'],
                static fn (NativeTagPrecompiler $compiler, bool $supportsActivation): string => $compiler(
                    '<'.($supportsActivation ? 'column' : 'native:column')." @{$name}=\"probe\"></".
                    ($supportsActivation ? 'column' : 'native:column').'>'
                ),
            );

            return self::$eventCache[$name] = str_contains($out, "'_event-{$name}'");
        } catch (Throwable) {
            return self::$eventCache[$name] = ! in_array($name, self::COMPILED_DIRECTIVE_FALLBACK, true);
        }
    }

    public static function reset(): void
    {
        self::$cache = [];
        self::$eventCache = [];
    }

    /**
     * @template T
     *
     * @param  array<string>  $shortFormTags
     * @param  callable(NativeTagPrecompiler, bool): T  $query
     * @return T
     */
    private static function queryCompiler(array $shortFormTags, callable $query): mixed
    {
        $supportsActivation = self::hasRuntimeMethod(NativeTagPrecompiler::class, 'active')
            && self::hasRuntimeMethod(NativeTagPrecompiler::class, 'setActive');
        $wasActive = $supportsActivation && NativeTagPrecompiler::active();

        try {
            if ($supportsActivation) {
                NativeTagPrecompiler::setActive(true);
            }

            $compiler = $supportsActivation
                ? new NativeTagPrecompiler($shortFormTags)
                : new NativeTagPrecompiler;

            return $query($compiler, $supportsActivation);
        } finally {
            if ($supportsActivation) {
                NativeTagPrecompiler::setActive($wasActive);
            }
        }
    }

    private static function hasRuntimeMethod(string $class, string $method): bool
    {
        return method_exists($class, $method);
    }
}
