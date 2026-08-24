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

#[RequiresPackage('nativephp/mobile')]
class LinePointsRule extends BaseRule
{
    public function getId(): string
    {
        return 'native-line-points';
    }

    public function getDescription(): string
    {
        return 'Reports line coordinates that are ignored or cast to unexpected values.';
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
            if ($this->nativeTagName($element) !== 'line' || ! $this->isLintableElement($element)) {
                return;
            }

            foreach (['from', 'to'] as $name) {
                $attributes = $this->compilerEffectiveAttributes(
                    $element,
                    fn (Attribute $attribute): array => $this->attributeMatchesName($attribute, $name)
                        ? $this->compilerAttributeKeys($attribute)
                        : [],
                );
                if ($attributes === null) {
                    continue;
                }

                foreach ($attributes as $attribute) {
                    if ($attribute->isBound() || ! $attribute->isStatic() || $attribute->hasComplexValue()) {
                        continue;
                    }

                    $value = $attribute->valueText();

                    if ($value === null) {
                        $context->report(
                            $element,
                            "{$name} needs an \"x,y\" value; a bare attribute is ignored."
                        );

                        continue;
                    }

                    $parts = explode(',', $value);

                    if (count($parts) !== 2) {
                        $context->report(
                            $element,
                            "{$name}=\"{$value}\" needs exactly two coordinates (\"x,y\"); the default position is used instead."
                        );

                        continue;
                    }

                    foreach ($parts as $part) {
                        $trimmed = trim($part);

                        if (is_numeric($trimmed) && is_finite((float) $trimmed)) {
                            continue;
                        }

                        if (is_numeric($trimmed)) {
                            $context->report(
                                $element,
                                "{$name}=\"{$value}\" contains non-finite coordinate '{$trimmed}'. Use a finite number."
                            );

                            break;
                        }

                        $shipped = implode(',', array_map(
                            self::castValue(...),
                            $parts
                        ));

                        $context->report(
                            $element,
                            "{$name}=\"{$value}\" casts non-numeric coordinate '{$trimmed}' to ".self::castValue($part)
                                .", drawing {$name} {$shipped}."
                        );

                        break;
                    }
                }
            }
        });
    }

    private static function castValue(string $part): string
    {
        $float = (float) trim($part);

        if (fmod($float, 1.0) === 0.0 && abs($float) < PHP_INT_MAX) {
            return (string) (int) $float;
        }

        return (string) $float;
    }
}
