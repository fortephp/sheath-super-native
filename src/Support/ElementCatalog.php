<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Support;

use Native\Mobile\Edge\ComponentRegistry;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\NativeElementCollector;
use ReflectionClassConstant;
use ReflectionMethod;
use Throwable;

final class ElementCatalog
{
    /**
     * @var array<string>
     */
    private const array CORE_TAGS = [
        'bottom-bar', 'bottom-nav', 'bottom-nav-item', 'button', 'canvas',
        'circle', 'column', 'divider', 'fab', 'gesture-area', 'icon',
        'image', 'line', 'pressable', 'rect', 'refreshable', 'row',
        'scroll-view', 'side-nav', 'side-nav-group', 'side-nav-header',
        'side-nav-item', 'spacer', 'stack', 'text', 'top-bar',
        'top-bar-action', 'top-bar-title', 'virtual-list', 'webview',
    ];

    /**
     * @var array<string>
     */
    private const array KNOWN_PLUGIN_TAGS = [
        'activity-indicator', 'badge', 'bare-text-input', 'bottom-sheet',
        'button-group', 'carousel', 'checkbox', 'chip',
        'filled-text-input', 'floating-overlay', 'horizontal-divider',
        'lazy-grid', 'list', 'list-item', 'list-section', 'modal',
        'native-drawer', 'outlined-text-input', 'progress-bar', 'radio',
        'radio-group', 'select', 'slider', 'tab', 'tab-row', 'toggle',
    ];

    /**
     * @var array<string>
     */
    private const array COLLECTOR_RENDERABLE_FALLBACK = [
        'column', 'row', 'stack', 'scroll-view', 'pressable', 'canvas',
        'spacer', 'divider', 'bottom-bar',
    ];

    /** @var array<string> */
    private const array COLLECTOR_RESERVED_FALLBACK = [
        'column', 'row', 'stack', 'scroll-view', 'pressable', 'canvas',
        'spacer', 'divider', 'bottom-bar', 'virtual-list',
        'text', 'button', 'webview',
    ];

    /** @var array<string, bool>|null */
    private static ?array $runtimeTags = null;

    /** @var array<string, bool> */
    private static array $componentTags = [];

    private static bool $runtimeResolved = false;

    public static function isKnownTag(string $tag): bool
    {
        $tag = self::normalizeTag($tag);

        $runtime = self::runtimeTags();

        if ($runtime !== null) {
            return isset($runtime[$tag]);
        }

        return in_array($tag, self::CORE_TAGS, true)
            || in_array($tag, self::KNOWN_PLUGIN_TAGS, true);
    }

    /**
     * @return array<string>
     */
    public static function knownTags(): array
    {
        $runtime = self::runtimeTags();

        if ($runtime !== null) {
            return array_keys($runtime);
        }

        return array_values(array_unique(array_merge(self::CORE_TAGS, self::KNOWN_PLUGIN_TAGS)));
    }

    /**
     * @return array<string>
     */
    public static function shortFormTags(): array
    {
        if (class_exists(ElementRegistry::class)) {
            try {
                $registered = array_keys(ElementRegistry::all());

                if ($registered !== []) {
                    return array_map(
                        self::normalizeTag(...),
                        $registered
                    );
                }
            } catch (Throwable) {
            }
        }

        $registeredCore = array_values(array_diff(
            self::CORE_TAGS,
            ['bottom-bar', 'virtual-list', 'webview']
        ));

        return array_values(array_unique(array_merge($registeredCore, self::KNOWN_PLUGIN_TAGS)));
    }

    public static function isReflected(): bool
    {
        return self::runtimeTags() !== null;
    }

    /**
     * @return array<string, bool>|null
     */
    private static function runtimeTags(): ?array
    {
        if (self::$runtimeResolved) {
            return self::$runtimeTags;
        }

        self::$runtimeResolved = true;
        self::$runtimeTags = null;
        self::$componentTags = [];

        if (! class_exists(ElementRegistry::class)) {
            return null;
        }

        try {
            $tags = [];
            foreach (array_keys(ElementRegistry::all()) as $type) {
                $tags[self::normalizeTag((string) $type)] = true;
            }

            if ($tags === []) {
                return null;
            }

            foreach (self::collectorRenderableTags() as $builtin) {
                $tags[$builtin] = true;
            }

            if (class_exists(ComponentRegistry::class)) {
                $reserved = array_fill_keys(self::collectorReservedTags(), true);

                foreach (array_keys(ComponentRegistry::all()) as $componentTag) {
                    $tag = self::normalizeTag((string) $componentTag);

                    if (isset($reserved[$tag])) {
                        continue;
                    }

                    $tags[$tag] = true;
                    self::$componentTags[$tag] = true;
                }
            }

            return self::$runtimeTags = $tags;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string>
     */
    private static function collectorRenderableTags(): array
    {
        if (! class_exists(NativeElementCollector::class)) {
            return self::COLLECTOR_RENDERABLE_FALLBACK;
        }

        try {
            $source = self::methodSource(
                new ReflectionMethod(NativeElementCollector::class, 'createElement')
            );
            $tags = $source === null ? [] : self::matchArmTags($source);

            return $tags === [] ? self::COLLECTOR_RENDERABLE_FALLBACK : $tags;
        } catch (Throwable) {
            return self::COLLECTOR_RENDERABLE_FALLBACK;
        }
    }

    private static function methodSource(ReflectionMethod $method): ?string
    {
        $file = $method->getFileName();
        if (! is_string($file)) {
            return null;
        }

        $lines = file($file);
        if (! is_array($lines)) {
            return null;
        }

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
    }

    /**
     * @return array<string>
     */
    private static function matchArmTags(string $source): array
    {
        if (preg_match('/\\$element\\s*=\\s*match\\s*\\(\\$type\\)\\s*\\{(?<arms>.*?)\\n\\s*\\};/s', $source, $match) !== 1) {
            return [];
        }

        preg_match_all("/'([^']+)'\\s*=>/", $match['arms'], $matches);

        return array_values(array_unique(array_map(self::normalizeTag(...), $matches[1])));
    }

    /**
     * @return array<string>
     */
    private static function collectorReservedTags(): array
    {
        if (class_exists(NativeElementCollector::class)) {
            try {
                $constant = new ReflectionClassConstant(
                    NativeElementCollector::class,
                    'COLLECTOR_BUILTIN_TYPES'
                );

                /** @var array<string> $value */
                $value = $constant->getValue();

                if ($value !== []) {
                    return array_map(
                        self::normalizeTag(...),
                        $value
                    );
                }
            } catch (Throwable) {
            }
        }

        return self::COLLECTOR_RESERVED_FALLBACK;
    }

    /**
     * @return array<string, bool>
     */
    public static function componentTags(): array
    {
        self::runtimeTags();

        return self::$componentTags;
    }

    /** @return class-string|null */
    public static function elementClass(string $tag): ?string
    {
        if (! class_exists(ElementRegistry::class)) {
            return null;
        }

        try {
            $type = str_replace('-', '_', strtolower($tag));
            $class = ElementRegistry::all()[$type] ?? null;

            return is_string($class) && class_exists($class) ? $class : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function reset(): void
    {
        self::$runtimeTags = null;
        self::$runtimeResolved = false;
        self::$componentTags = [];
    }

    private static function normalizeTag(string $tag): string
    {
        return str_replace('_', '-', strtolower($tag));
    }
}
