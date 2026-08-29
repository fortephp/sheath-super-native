<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules\Elements;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\NativePhp\Rules\BaseRule;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleContext;
use Native\Mobile\Edge\Enums\AlignItems;
use Native\Mobile\Edge\Enums\AlignSelf;
use Native\Mobile\Edge\Enums\JustifyContent;
use Native\Mobile\Edge\Enums\TextAlign;
use Throwable;

#[RequiresPackage('nativephp/mobile')]
class InvalidEnumValueRule extends BaseRule
{
    /**
     * @var array<string, class-string>
     */
    private const array ENUM_ATTRS = [
        'alignItems' => AlignItems::class,
        'alignSelf' => AlignSelf::class,
        'justifyContent' => JustifyContent::class,
        'textAlign' => TextAlign::class,
    ];

    /**
     * @var array<string>
     */
    private const array AXIS_VALUES = ['vertical', 'horizontal', 'both'];

    public function getId(): string
    {
        return 'native-invalid-enum-value';
    }

    public function getDescription(): string
    {
        return 'Reports unsupported alignment and axis values that leave the native default in place.';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
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

            if ($this->elementHasUnmodelledAttributes($element)) {
                return;
            }

            $effective = $this->compilerEffectiveAttributes(
                $element,
                fn (Attribute $attribute): array => $this->compilerKeysForValidatedAttribute($attribute, $tag),
            );

            if ($effective === null) {
                return;
            }

            foreach ($effective as $attribute) {
                /** @var Attribute $attribute */
                if ($attribute->isBound() || ! $attribute->isStatic() || $attribute->hasComplexValue()) {
                    continue;
                }

                $name = $attribute->nameText();
                $value = $attribute->valueText();

                if ($name === 'axis' && $tag === 'scroll-view') {
                    if ($value === null || ! in_array($value, self::AXIS_VALUES, true)) {
                        $display = $value === null ? 'a bare axis attribute' : 'axis="'.$value.'"';
                        $context->report(
                            $element,
                            "{$display} is invalid. Use vertical, horizontal, or both (lowercase); otherwise <scroll-view> stays vertical."
                        );
                    }

                    continue;
                }

                $enum = self::ENUM_ATTRS[$name] ?? null;

                if ($enum === null || ! class_exists($enum)
                    || ($name === 'textAlign' && $tag !== 'text')) {
                    continue;
                }

                if ($value === null || trim($value) === '') {
                    $display = $value === null ? $name.' without a value' : $name.'="'.$value.'"';
                    $context->report(
                        $element,
                        "{$display} is invalid and will be ignored; the native default remains."
                    );

                    continue;
                }

                $value = trim($value);

                try {
                    $parsed = $enum::parse($value);
                } catch (Throwable) {
                    continue;
                }

                if ($parsed === null) {
                    $context->report(
                        $element,
                        "{$attribute->nameText()}=\"{$value}\" is invalid and will be ignored; the native default remains."
                    );
                }
            }
        });
    }

    /** @return list<string> */
    private function compilerKeysForValidatedAttribute(Attribute $attribute, string $tag): array
    {
        $name = $attribute->nameText();

        if (isset(self::ENUM_ATTRS[$name])) {
            return [$name];
        }

        if ($name === 'axis' && $tag === 'scroll-view') {
            return [$name];
        }

        return [];
    }
}
