<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Support;

use Native\Mobile\Edge\NativeTagPrecompiler;
use ReflectionClassConstant;
use ReflectionMethod;
use Throwable;

final class ElementEventCatalog
{
    /** @var array<string, string> */
    private const array ALIASES = [
        'tap' => 'press',
        'longTap' => 'longPress',
        'tapDown' => 'pressDown',
        'tapUp' => 'pressUp',
    ];

    /** @var array<string, string>|null */
    private static ?array $aliases = null;

    /** @var array<string, string> */
    private const array HANDLERS = [
        'press' => 'onPress',
        'longPress' => 'onLongPress',
        'doubleTap' => 'onDoubleTap',
        'pressDown' => 'onPressDown',
        'pressUp' => 'onPressUp',
        'change' => 'onChange',
        'selectionChange' => 'onSelectionChange',
        'submit' => 'onSubmit',
        'dismiss' => 'onDismiss',
        'refresh' => 'onRefresh',
        'endReached' => 'onEndReached',
        'swipeDelete' => 'onSwipeDelete',
        'swipe' => 'onSwipe',
        'pinchEnd' => 'onPinchEnd',
        'navigated' => 'onNavigated',
    ];

    /**
     * @var list<string>
     */
    private const array BASE_ONLY_FALLBACK = [
        'column', 'row', 'stack', 'scroll-view', 'pressable', 'canvas',
        'spacer', 'divider', 'bottom-bar', 'text', 'image', 'icon', 'rect',
        'circle', 'line',
    ];

    public static function canDispatch(string $tag, string $directive): ?bool
    {
        if (isset(ElementCatalog::componentTags()[$tag])) {
            return null;
        }

        $canonical = self::canonicalDirective($directive);
        $handler = self::HANDLERS[$canonical] ?? null;
        if ($handler === null) {
            return null;
        }

        $class = ElementCatalog::elementClass($tag);
        if ($class !== null) {
            if (! method_exists($class, $handler)) {
                return false;
            }

            try {
                return new ReflectionMethod($class, $handler)->isPublic() ? true : null;
            } catch (Throwable) {
                return null;
            }
        }

        if (in_array($tag, self::BASE_ONLY_FALLBACK, true)) {
            return in_array($canonical, ['press', 'longPress', 'doubleTap', 'pressDown', 'pressUp'], true);
        }

        return null;
    }

    public static function canonicalDirective(string $directive): string
    {
        self::$aliases ??= self::readAliases();

        return self::$aliases[$directive] ?? $directive;
    }

    /** @return array<string, string> */
    private static function readAliases(): array
    {
        if (class_exists(NativeTagPrecompiler::class)) {
            try {
                $value = new ReflectionClassConstant(NativeTagPrecompiler::class, 'TAP_ALIASES')->getValue();

                if (is_array($value)) {
                    /** @var array<string, string> $value */
                    return $value;
                }
            } catch (Throwable) {
            }
        }

        return self::ALIASES;
    }

    public static function reset(): void
    {
        self::$aliases = null;
    }
}
