<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Support;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;

final class ViewDetector
{
    /**
     * @var array<string>
     */
    private const array UNAMBIGUOUS_TAGS = [
        'column', 'row', 'stack', 'scroll-view', 'pressable',
        'top-bar', 'bottom-nav', 'side-nav', 'fab', 'gesture-area',
        'refreshable', 'virtual-list', 'bottom-sheet', 'activity-indicator',
        'bottom-bar', 'lazy-grid',
    ];

    /**
     * @param  array<string>  $paths  Path fragments that mark a native view
     */
    public static function isNativeView(
        Document $document,
        string $filePath,
        array $paths = ['views/native/'],
        bool $inferFromTags = true,
    ): bool {
        $normalized = str_replace('\\', '/', strtolower($filePath));

        foreach ($paths as $path) {
            if ($path !== '' && str_contains($normalized, strtolower(str_replace('\\', '/', $path)))) {
                return true;
            }
        }

        if (! $inferFromTags) {
            return false;
        }

        foreach ($document->getElements() as $element) {
            /** @var ElementNode $element */
            $tag = $element->tagNameText();
            $start = $element->startOffset();
            $hasNativePrefix = $start >= 0 && preg_match(
                '/\G<\s*native\s*:\s*[A-Za-z0-9_-]+/A',
                $document->source(),
                offset: $start,
            ) === 1;

            if (str_starts_with($tag, 'native:')
                || $hasNativePrefix) {
                return true;
            }

            if (in_array($tag, self::UNAMBIGUOUS_TAGS, true)) {
                return true;
            }
        }

        return false;
    }
}
