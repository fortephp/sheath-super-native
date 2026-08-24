<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules\Interaction;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\NativePhp\Support\ComponentViewMap;
use Forte\Sheath\NativePhp\Support\Suggestions;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleContext;

#[RequiresPackage('nativephp/mobile')]
class ModelPropertyRule extends ComponentAwareRule
{
    public function getId(): string
    {
        return 'native-model-property';
    }

    public function getDescription(): string
    {
        return 'Reports model bindings that do not target a public, unlocked property on the owning component.';
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

        $owner = ComponentViewMap::soleComponentForPath($context->getFilePath(), $this->componentPaths());

        if ($owner === null || ! $owner['reflected']) {
            return;
        }

        $componentFile = basename($owner['file']);

        $document->getElements()->each(function (ElementNode $element) use ($componentFile, $context, $owner): void {
            if (! $this->isLintableElement($element, includeComponents: true)) {
                return;
            }

            $attributes = $this->compilerEffectiveAttributes($element);
            if ($attributes === null) {
                return;
            }

            foreach ($attributes as $attribute) {
                $directive = $this->modelBindingDirective($attribute);

                if ($directive === null) {
                    continue;
                }

                $property = $this->modelBindingPropertyName($attribute);

                if ($property === null) {
                    continue;
                }

                if (isset($owner['locked'][$property])) {
                    $context->report(
                        $element,
                        "{$directive}=\"{$property}\" binds locked property \${$property} in {$componentFile}. Use an unlocked public property."
                    );

                    continue;
                }

                if (isset($owner['readonly'][$property])) {
                    $context->report(
                        $element,
                        "{$directive}=\"{$property}\" binds readonly property \${$property} in {$componentFile}. Use a writable public property."
                    );

                    continue;
                }

                if (isset($owner['properties'][$property])) {
                    continue;
                }

                if (isset($owner['nonPublicProperties'][$property])) {
                    $context->report(
                        $element,
                        "{$directive}=\"{$property}\" binds non-public property \${$property} in {$componentFile}. Make it public."
                    );

                    continue;
                }

                $context->report(
                    $element,
                    "{$directive}=\"{$property}\" cannot find public property \${$property} in {$componentFile}."
                        .Suggestions::hint($property, array_keys($owner['properties']))
                );
            }
        });
    }
}
