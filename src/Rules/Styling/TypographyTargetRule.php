<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules\Styling;

use Forte\Ast\Document\Document;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\NativePhp\Rules\BaseRule;
use Forte\Sheath\NativePhp\Support\TailwindOracle;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleContext;

#[RequiresPackage('nativephp/mobile')]
class TypographyTargetRule extends BaseRule
{
    /**
     * @var array<string, array<string>>
     */
    private const array CONSUMERS = [
        'fontSize' => ['text'],
        'color' => ['text', 'icon', 'activity-indicator'],
        'fontWeight' => ['text'],
        'fontStyle' => ['text'],
        'fontFamily' => ['text'],
        'underline' => ['text'],
        'lineThrough' => ['text'],
        'textTransform' => ['text'],
        'letterSpacing' => ['text'],
        'lineHeight' => ['text'],
        'lineHeightPx' => ['text'],
        'textAlign' => ['text'],
    ];

    /**
     * @var array<string>
     */
    private const array KNOWN_ELEMENTS = [
        'column', 'row', 'stack', 'scroll-view', 'pressable', 'spacer',
        'divider', 'image', 'canvas', 'rect', 'circle', 'line', 'refreshable',
        'gesture-area', 'text', 'icon', 'activity-indicator',
    ];

    public function getId(): string
    {
        return 'native-typography-target';
    }

    public function getDescription(): string
    {
        return 'Reports text styles on elements that render no text; native text styles do not inherit.';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        if (! TailwindOracle::available() || ! $this->appliesTo($document, $context)) {
            return;
        }

        foreach ($this->staticClassTokenEntriesFor($document) as [$element, $token]) {
            $tag = $this->nativeTagName($element);

            if (! $this->isLintableElement($element)
                || ! in_array($tag, self::KNOWN_ELEMENTS, true)) {
                continue;
            }

            $parsed = (TailwindOracle::parseOn('ios', $token) ?? [])
                + (TailwindOracle::parseOn('android', $token) ?? []);

            $keys = array_keys($parsed);
            if (isset($parsed['dark']) && is_array($parsed['dark'])) {
                array_push(
                    $keys,
                    ...array_intersect(array_keys($parsed['dark']), ['color', 'fontSize'])
                );
            }

            foreach (array_unique($keys) as $key) {
                $consumers = self::CONSUMERS[$key] ?? null;

                if ($consumers !== null && ! in_array($tag, $consumers, true)) {
                    $context->report(
                        $element,
                        "'{$token}' has no effect on <{$tag}>; text styles do not inherit. Move it to an element that renders text."
                    );

                    break;
                }
            }
        }
    }
}
