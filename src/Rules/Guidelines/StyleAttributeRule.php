<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules\Guidelines;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\NativePhp\Rules\BaseRule;
use Forte\Sheath\NativePhp\Support\ElementAttributeCatalog;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleContext;

#[RequiresPackage('nativephp/mobile')]
class StyleAttributeRule extends BaseRule
{
    public function getId(): string
    {
        return 'native-no-style-attribute';
    }

    public function getDescription(): string
    {
        return 'Reports style attributes that native elements do not apply.';
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

            $accepted = ElementAttributeCatalog::forTag($tag);
            if ($accepted === null || in_array('style', $accepted, true)) {
                return;
            }

            $styles = $this->compilerEffectiveAttributes(
                $element,
                fn (Attribute $attribute): array => $this->attributeMatchesName($attribute, 'style')
                    ? $this->compilerAttributeKeys($attribute)
                    : [],
            );

            if ($styles !== null && $styles !== []) {
                $context->report(
                    $element,
                    'style="..." is not applied to native elements. Use class utilities instead.'
                );
            }
        });
    }
}
