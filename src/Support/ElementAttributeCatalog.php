<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Support;

use Native\Mobile\Edge\NativeElementCollector;
use ReflectionMethod;
use Throwable;

final class ElementAttributeCatalog
{
    /** @var list<string> */
    private const array GENERIC = [
        'fill', 'fillWidth', 'fillHeight', 'width', 'height',
        'minWidth', 'maxWidth', 'minHeight', 'maxHeight',
        'padding', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
        'margin', 'marginTop', 'marginRight', 'marginBottom', 'marginLeft',
        'gap', 'center', 'safeArea', 'safeAreaTop', 'safeAreaBottom',
        'flexGrow', 'flexShrink', 'flexBasis', 'flexWrap', 'flexDirection',
        'aspectRatio', 'alignSelf', 'alignItems', 'justifyContent',
        'positionType', 'positionTop', 'positionRight', 'positionBottom', 'positionLeft',
        'bg', 'borderRadius', 'borderRadiusTopLeft', 'borderRadiusTopRight',
        'borderRadiusBottomRight', 'borderRadiusBottomLeft',
        'borderWidth', 'borderColor', 'opacity', 'elevation',
        'glass', 'selectable', 'scroll-anchor',
        'animate-duration', 'animate-delay', 'animate-easing', 'animate-loop',
        'translate-x', 'translate-y', 'scale', 'rotate',
        'press-scale', 'press-opacity', 'press-translate-y',
        'gradient', 'dark', 'class', 'native:key', 'native-key',
        'native:model', 'native:poll', 'ref',
        'a11y-label', 'a11yLabel', 'a11y-hint', 'a11yHint',
        'native-poll',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const array ELEMENT_FALLBACKS = [
        'text' => [
            'text', 'font-size', 'fontSize', 'font-weight', 'fontWeight',
            'font-style', 'fontStyle', 'font-family', 'fontFamily', 'font',
            'underline', 'line-through', 'lineThrough', 'text-transform',
            'textTransform', 'letter-spacing', 'letterSpacing', 'line-height',
            'lineHeight', 'line-height-px', 'lineHeightPx', 'color',
            'text-align', 'textAlign', 'max-lines', 'maxLines',
            'content-transition', 'contentTransition',
        ],
        'image' => ['src', 'fit', 'tintColor', 'alt'],
        'icon' => ['name', 'ios', 'android', 'size', 'color', 'dark-color', 'darkColor'],
        'scroll-view' => ['axis', 'horizontal', 'showsIndicators', 'shows-indicators'],
        'refreshable' => ['showsIndicators', 'shows-indicators'],
        'pressable' => ['menu'],
        'fab' => [
            'icon', 'label', 'url', 'size', 'position', 'bottom-offset',
            'bottomOffset', 'edge-offset', 'edgeOffset', 'corner-radius',
            'cornerRadius', 'container-color', 'containerColor', 'content-color',
            'contentColor', 'ios-icon', 'iosIcon', 'ios', 'android-icon',
            'androidIcon', 'android', 'menu',
        ],
        'top-bar' => [
            'title', 'subtitle', 'custom', 'back', 'background-color',
            'backgroundColor', 'text-color', 'textColor', 'font-name', 'fontName',
            'show-navigation-icon', 'showNavigationIcon', 'display-mode',
            'displayMode', 'scroll-behavior', 'scrollBehavior',
            'search-placeholder', 'searchPlaceholder', 'search-on-query',
            'searchOnQuery', 'search-debounce-ms', 'searchDebounceMs',
        ],
        'top-bar-action' => [
            'id', 'icon', 'label', 'url', 'event', 'destructive', 'divider',
            'material-variant', 'material_variant', 'ios-icon', 'iosIcon', 'ios',
            'android-icon', 'androidIcon', 'android',
        ],
        'top-bar-title' => [],
        'bottom-nav' => [
            'dark', 'custom', 'label-visibility', 'labelVisibility',
            'active-color', 'activeColor', 'background-color', 'backgroundColor',
            'text-color', 'textColor', 'font-name', 'fontName',
            'minimize-on-scroll', 'minimizeOnScroll',
        ],
        'bottom-nav-item' => [
            'id', 'icon', 'url', 'label', 'badge', 'active', 'news', 'search',
            'badge-color', 'badgeColor', 'material-variant', 'material_variant',
            'search-placeholder', 'search_placeholder', 'search-debounce-ms',
            'search_debounce_ms', 'ios-icon', 'iosIcon', 'ios', 'android-icon',
            'androidIcon', 'android',
        ],
        'side-nav' => ['dark', 'custom', 'label-visibility', 'labelVisibility', 'gestures-enabled', 'gesturesEnabled'],
        'side-nav-item' => [
            'id', 'label', 'url', 'icon', 'badge', 'active', 'badge-color',
            'badgeColor', 'open-in-browser', 'openInBrowser',
        ],
        'side-nav-group' => ['heading', 'expanded', 'icon'],
        'side-nav-header' => [
            'title', 'subtitle', 'icon', 'event', 'pinned', 'background-color',
            'backgroundColor', 'image-url', 'imageUrl', 'show-close-button',
            'showCloseButton',
        ],
        'gesture-area' => ['pan-y', 'pinch', 'pinch-min', 'pinch-max', 'swipe-fingers'],
        'line' => ['from', 'to'],
        'rect' => ['left', 'top'],
        'circle' => ['left', 'top'],
        'column' => [], 'row' => [], 'stack' => [], 'canvas' => [],
        'spacer' => [], 'divider' => [], 'bottom-bar' => [],
    ];

    /**
     * @return list<string>|null
     */
    public static function forTag(string $tag): ?array
    {
        $fallback = self::ELEMENT_FALLBACKS[$tag] ?? null;
        $reflected = ReflectedElementAttributes::forTag($tag);

        if ($fallback === null && $reflected === null) {
            return null;
        }

        return array_values(array_unique([
            ...self::GENERIC,
            ...array_keys(self::capturedAttributes()),
            ...($fallback ?? []),
            ...($reflected ?? []),
        ]));
    }

    /** @return array<string, string> attribute name => prop name */
    public static function capturedAttributes(): array
    {
        if (! class_exists(NativeElementCollector::class)) {
            return [];
        }

        try {
            $captured = [];
            $registered = new ReflectionMethod(
                NativeElementCollector::class,
                'capturedAttributes',
            )->invoke(null);

            if (! is_array($registered)) {
                return [];
            }

            foreach ($registered as $attribute => $prop) {
                if (is_string($attribute) && is_string($prop)) {
                    $captured[$attribute] = $prop;
                }
            }

            return $captured;
        } catch (Throwable) {
            return [];
        }
    }
}
