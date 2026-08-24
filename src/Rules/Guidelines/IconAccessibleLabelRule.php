<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules\Guidelines;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\NativePhp\Rules\BaseRule;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\Concerns\ChecksAccessibility;
use Forte\Sheath\Rules\RuleContext;

#[RequiresPackage('nativephp/mobile')]
class IconAccessibleLabelRule extends BaseRule
{
    use ChecksAccessibility;

    /** @var array<string> */
    private const array INTERACTIVE = ['pressable', 'fab'];

    /** @var array<string> */
    private const array FAB_ICONS = [
        'icon', 'ios-icon', 'iosIcon', 'ios', 'android-icon', 'androidIcon', 'android',
    ];

    public function getId(): string
    {
        return 'native-icon-a11y-label';
    }

    public function getDescription(): string
    {
        return 'Reports icon-only controls with no accessible name.';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        if (! $this->appliesTo($document, $context)) {
            return;
        }

        $document->getElements()->each(function (ElementNode $element) use ($context): void {
            $tag = $this->nativeTagName($element);

            if (! $this->isLintableElement($element)) {
                return;
            }

            $message = $this->messageFor($element, $tag);
            if ($message !== null) {
                $context->report($element, $message);
            }
        });
    }

    private function messageFor(ElementNode $element, string $tag): ?string
    {
        if ($tag === 'icon') {
            return $this->hasUnlabelledPressPath($element)
                ? 'Interactive <icon> needs an a11y-label.'
                : null;
        }

        if (! in_array($tag, self::INTERACTIVE, true)) {
            return null;
        }

        if ($tag === 'fab') {
            return $this->hasUnlabelledFabPath($element)
                ? 'Icon-only <fab> needs an a11y-label or visible label.'
                : null;
        }

        return $this->isIconOnly($element)
            && $this->hasUnlabelledPressPath($element)
            && ! $this->descendantMaySupplyAccessibleContent($element)
            ? "Icon-only <{$tag}> needs an a11y-label or visible text."
            : null;
    }

    private function hasUnlabelledPressPath(ElementNode $element): bool
    {
        $paths = $this->accessibleAttributeRenderPaths($element);
        if ($paths === null) {
            return false;
        }

        foreach ($paths as $path) {
            $hasPress = false;
            $hasLabel = false;

            foreach ($path as $attribute) {
                $name = $attribute->nameText();

                if ($this->attributeProvidesPressInteraction($name)) {
                    $hasPress = true;
                }

                if ($this->attributeMatchesName($attribute, ['a11y-label', 'a11yLabel'])
                    && self::attributeMaySupplyLabel($attribute)) {
                    $hasLabel = true;
                }
            }

            if ($hasPress && ! $hasLabel) {
                return true;
            }
        }

        return false;
    }

    private function hasUnlabelledFabPath(ElementNode $element): bool
    {
        $paths = $this->accessibleAttributeRenderPaths($element);
        if ($paths === null) {
            return false;
        }

        foreach ($paths as $path) {
            $hasIcon = false;
            $hasVisibleLabel = false;
            $hasAccessibleLabel = false;
            $hasInteraction = false;

            foreach ($path as $attribute) {
                $name = $attribute->nameText();

                if ($this->attributeMatchesName($attribute, self::FAB_ICONS)) {
                    $hasIcon = $hasIcon || self::attributeMaySupplyLabel($attribute);
                } elseif ($this->attributeMatchesName($attribute, 'label')) {
                    $hasVisibleLabel = $hasVisibleLabel || self::attributeMaySupplyLabel($attribute);
                } elseif ($this->attributeMatchesName($attribute, ['a11y-label', 'a11yLabel'])) {
                    $hasAccessibleLabel = $hasAccessibleLabel || self::attributeMaySupplyLabel($attribute);
                }

                if ($this->attributeProvidesInteraction($name)) {
                    $hasInteraction = true;
                }
            }

            if ($hasIcon
                && $hasInteraction
                && ! $hasVisibleLabel
                && ! $hasAccessibleLabel) {
                return true;
            }
        }

        return false;
    }

    private function attributeProvidesInteraction(string $name): bool
    {
        return $name === 'url'
            || $this->attributeProvidesPressInteraction($name);
    }

    private function attributeProvidesPressInteraction(string $name): bool
    {
        return preg_match('/^@(?:press(?:Down|Up)?|longPress|doubleTap|tap(?:Down|Up)?|longTap|navigate)(?:[.(]|$)/', $name) === 1
            || in_array($name, ['_press', '_longPress', '_doubleTap', '_pressDown', '_pressUp', '_navigate'], true);
    }

    /** @return list<list<Attribute>>|null */
    private function accessibleAttributeRenderPaths(ElementNode $element): ?array
    {
        return $this->compilerEffectiveAttributeRenderPaths(
            $element,
            fn (Attribute $attribute): array => $this->attributeMatchesName(
                $attribute,
                ['a11y-label', 'a11yLabel'],
            ) ? ['accessible-label'] : $this->compilerAttributeKeys($attribute),
        );
    }

    private function descendantMaySupplyAccessibleContent(ElementNode $element): bool
    {
        foreach ($element->descendants() as $descendant) {
            if (! $descendant instanceof ElementNode) {
                continue;
            }

            foreach (['a11y-label', 'a11yLabel', 'alt'] as $name) {
                $attributes = $this->compilerEffectiveAttributes(
                    $descendant,
                    fn (Attribute $attribute): array => $this->attributeMatchesName($attribute, $name)
                        ? [$name]
                        : [],
                );

                foreach ($attributes ?? [] as $attribute) {
                    if (self::attributeMaySupplyLabel($attribute)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function attributeMaySupplyLabel(Attribute $attribute): bool
    {
        return $attribute->isDynamic()
            || $attribute->hasComplexValue()
            || trim((string) $attribute->valueText()) !== '';
    }

    private function isIconOnly(ElementNode $element): bool
    {
        $inner = $element->innerContent();

        if (! preg_match('/<(?:native:)?icon\b/i', $inner)) {
            return false;
        }

        return ! $this->hasTextContent($element);
    }
}
