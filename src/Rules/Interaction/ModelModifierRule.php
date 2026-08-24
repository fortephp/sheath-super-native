<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules\Interaction;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\NativePhp\Rules\BaseRule;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleContext;

#[RequiresPackage('nativephp/mobile')]
class ModelModifierRule extends BaseRule
{
    public function getId(): string
    {
        return 'native-model-modifier';
    }

    public function getDescription(): string
    {
        return 'Reports model syntax that cannot bind or changes the intended synchronization behavior.';
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
            if (! $this->isLintableElement($element, includeComponents: true)) {
                return;
            }

            if ($this->elementHasUnmodelledAttributes($element)) {
                return;
            }

            $effectiveModels = $this->compilerEffectiveAttributes(
                $element,
                fn (Attribute $attribute): array => $this->modelBindingDirective($attribute) === null
                    ? []
                    : $this->compilerAttributeKeys($attribute),
            );

            if ($effectiveModels === null) {
                return;
            }

            $effectiveOffsets = array_fill_keys(
                array_map(
                    static fn (Attribute $attribute): int => $attribute->startOffset(),
                    $effectiveModels,
                ),
                true,
            );

            foreach ($this->attributesInRenderStructure($element) as $attribute) {
                /** @var Attribute $attribute */
                $name = $attribute->nameText();

                if ($this->modelBindingDirective($attribute) !== null
                    && $this->hasCompilerIncompatibleUnquotedValue($attribute)) {
                    $context->report($element, $this->unquotedValueMessage($name));

                    continue;
                }

                if ($this->modelBindingDirective($attribute) !== null
                    && $this->hasCompilerIncompatibleSeparator($attribute)) {
                    $context->report($element, $this->separatorMessage($name));

                    continue;
                }

                if (str_starts_with($name, '@model.')) {
                    $nativeName = 'native:model'.substr($name, strlen('@model'));

                    $context->report(
                        $element,
                        "@model does not support modifiers. Use '{$nativeName}' instead."
                    );

                    continue;
                }

                if ($this->modelBindingDirective($attribute) !== null
                    && ! isset($effectiveOffsets[$attribute->startOffset()])) {
                    continue;
                }

                if (! str_starts_with($name, 'native:model.')) {
                    $modifiers = null;
                } else {
                    $modifiers = substr($name, strlen('native:model.'));
                    if (preg_match('/^debounce\.(\d+)ms$/', $modifiers, $duration) === 1
                        && (int) $duration[1] === 0) {
                        $context->report(
                            $element,
                            'debounce.0ms is ignored. Use a duration greater than 0ms.'
                        );
                    } elseif (! in_array($modifiers, ['live', 'blur', 'lazy', 'debounce'], true)
                        && preg_match('/^debounce\.\d+ms$/', $modifiers) !== 1) {
                        $context->report(
                            $element,
                            "native:model modifier '{$modifiers}' is invalid. Use live, blur, lazy, debounce, or debounce.<milliseconds>ms."
                        );
                    }
                }

                $directive = $this->modelBindingDirective($attribute);
                if ($directive !== null && $this->modelBindingPropertyName($attribute) === null) {
                    $context->report(
                        $element,
                        "{$directive} needs a literal PHP property name."
                    );
                }
            }
        });
    }
}
