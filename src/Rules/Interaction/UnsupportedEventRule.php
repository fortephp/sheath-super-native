<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules\Interaction;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\NativePhp\Rules\BaseRule;
use Forte\Sheath\NativePhp\Support\ElementCatalog;
use Forte\Sheath\NativePhp\Support\ElementEventCatalog;
use Forte\Sheath\NativePhp\Support\PrecompilerOracle;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleContext;

#[RequiresPackage('nativephp/mobile')]
class UnsupportedEventRule extends BaseRule
{
    public function getId(): string
    {
        return 'native-unsupported-event';
    }

    public function getDescription(): string
    {
        return 'Reports event bindings that the target element or child component cannot receive.';
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

        $componentTags = ElementCatalog::componentTags();

        $document->getElements()->each(function (ElementNode $element) use ($context, $componentTags): void {
            $tag = $this->nativeTagName($element);

            if (! $this->isLintableElement($element, includeComponents: true)
                || $this->elementHasUnmodelledAttributes($element)) {
                return;
            }

            $attributes = $this->compilerEffectiveAttributes(
                $element,
                fn (Attribute $attribute): array => str_starts_with($attribute->nameText(), '@')
                    ? $this->compilerAttributeKeys($attribute)
                    : [],
            );
            if ($attributes === null) {
                return;
            }

            foreach ($attributes as $attribute) {
                /** @var Attribute $attribute */
                if ($attribute->isBladeConstruct()) {
                    continue;
                }

                $name = $attribute->nameText();

                if (! str_starts_with($name, '@')) {
                    continue;
                }

                $event = (string) strtok(substr($name, 1), '.(');

                if ($event === '') {
                    continue;
                }

                if (PrecompilerOracle::isArbitraryEventBinding($event)) {
                    if (! isset($componentTags[$tag])) {
                        $context->report(
                            $element,
                            "@{$event} is ignored on <{$tag}>; custom events work only on child components."
                        );
                    }

                    continue;
                }

                if (isset($componentTags[$tag]) && $event !== 'model') {
                    $context->report(
                        $element,
                        "@{$event} is reserved on child component <{$tag}>. Use a custom event name."
                    );

                    continue;
                }

                if (ElementEventCatalog::canDispatch($tag, $event) === false) {
                    $context->report(
                        $element,
                        "@{$event} is not supported by <{$tag}>. Use a supported event such as @press."
                    );
                }
            }
        });
    }
}
