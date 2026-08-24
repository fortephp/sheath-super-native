<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules\Elements;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\NativePhp\Rules\BaseRule;
use Forte\Sheath\NativePhp\Support\ElementCatalog;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\Concerns\TraversesRenderedTree;
use Forte\Sheath\Rules\RuleContext;

#[RequiresPackage('nativephp/mobile')]
class ComponentSlotContentRule extends BaseRule
{
    use TraversesRenderedTree;

    public function getId(): string
    {
        return 'native-component-slot-content';
    }

    public function getDescription(): string
    {
        return 'Reports native output inside child-component tags that do not support slots.';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $componentTags = ElementCatalog::componentTags();

        if ($componentTags === [] || ! $this->appliesTo($document, $context)) {
            return;
        }

        $document->getElements()->each(function (ElementNode $element) use ($context, $componentTags): void {
            if (! isset($componentTags[$this->nativeTagName($element)])
                || ! $this->usesNativeCompilerSyntax($element)) {
                return;
            }

            $child = $this->firstNativeOutputDescendant($element);

            if ($child instanceof ElementNode) {
                $context->report(
                    $child,
                    '<'.$element->tagNameText().'> cannot contain native output. Pass the needed data as attributes instead.'
                );
            }
        });
    }

    private function firstNativeOutputDescendant(ElementNode $element): ?ElementNode
    {
        foreach ($this->renderedChildElements($element) as $child) {
            if ($this->usesNativeCompilerSyntax($child)) {
                return $child;
            }

            $nested = $this->firstNativeOutputDescendant($child);
            if ($nested instanceof ElementNode) {
                return $nested;
            }
        }

        return null;
    }
}
