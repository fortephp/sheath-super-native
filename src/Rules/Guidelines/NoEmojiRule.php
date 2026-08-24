<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules\Guidelines;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\TextNode;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\NativePhp\Rules\BaseRule;
use Forte\Sheath\NativePhp\Support\ElementAttributeCatalog;
use Forte\Sheath\NativePhp\Support\ElementCatalog;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleContext;

#[RequiresPackage('nativephp/mobile')]
class NoEmojiRule extends BaseRule
{
    private const string EMOJI_PATTERN = '/(?:\p{Emoji_Presentation}|\p{Regional_Indicator}|[#*0-9]\x{FE0F}?\x{20E3}|\p{Emoji}\x{FE0F})/u';

    public function getId(): string
    {
        return 'native-no-emoji';
    }

    public function getDescription(): string
    {
        return 'Reports emoji in native interface copy as an icon-system consistency guideline.';
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

        $reported = [];
        $document->allOfType(TextNode::class, true)->each(function (TextNode $text) use ($context, &$reported): void {
            if (preg_match(self::EMOJI_PATTERN, $text->getDocumentContent()) !== 1) {
                return;
            }

            $ancestor = $text->getParent();
            while ($ancestor !== null) {
                if ($ancestor instanceof ElementNode
                    && $this->nativeTagName($ancestor) === 'button'
                    && ElementCatalog::isKnownTag('button')
                    && $this->usesNativeCompilerSyntax($ancestor)) {
                    if (! isset($reported[$ancestor->index()])) {
                        $reported[$ancestor->index()] = true;
                        $context->report(
                            $ancestor,
                            'Emoji in <button> slot text becomes its label. Use <native:icon> when it represents an interface icon.'
                        );
                    }

                    break;
                }

                if ($ancestor instanceof ElementNode
                    && $this->nativeTagName($ancestor) === 'text'
                    && ElementCatalog::isKnownTag('text')) {
                    if ($this->isInsideNativeButton($ancestor)) {
                        $ancestor = $ancestor->getParent();

                        continue;
                    }

                    if ($this->isBlockedByComponentSlot($ancestor)) {
                        break;
                    }

                    if (! isset($reported[$ancestor->index()])) {
                        $reported[$ancestor->index()] = true;
                        $tag = $this->nativeTagName($ancestor);
                        $context->report(
                            $ancestor,
                            "Emoji in <{$tag}> is plain text. Use <native:icon> when it represents an interface icon."
                        );
                    }

                    break;
                }

                $ancestor = $ancestor->getParent();
            }
        });

        $document->getElements()->each(function (ElementNode $element) use ($context): void {
            $tag = $this->nativeTagName($element);

            if (! $this->isLintableElement($element)) {
                return;
            }

            $accepted = ElementAttributeCatalog::forTag($tag);
            foreach (['label', 'title', 'subtitle'] as $attrName) {
                if ($accepted !== null && ! in_array($attrName, $accepted, true)) {
                    continue;
                }

                $attributes = $this->compilerEffectiveAttributes(
                    $element,
                    fn (Attribute $attribute): array => $this->attributeMatchesName($attribute, $attrName)
                        ? $this->compilerAttributeKeys($attribute)
                        : [],
                );
                if ($attributes === null) {
                    continue;
                }

                foreach ($attributes as $attr) {
                    if ($attr->isStatic() && ! $attr->hasComplexValue()
                        && preg_match(self::EMOJI_PATTERN, (string) $attr->valueText()) === 1) {
                        $context->report(
                            $element,
                            "Emoji in {$attrName} is plain text. Use an icon attribute when it represents an interface icon."
                        );

                        break;
                    }
                }
            }
        });
    }

    private function isInsideNativeButton(ElementNode $element): bool
    {
        $ancestor = $element->getParent();

        while ($ancestor !== null) {
            if ($ancestor instanceof ElementNode
                && $this->nativeTagName($ancestor) === 'button'
                && ElementCatalog::isKnownTag('button')
                && $this->usesNativeCompilerSyntax($ancestor)) {
                return true;
            }

            $ancestor = $ancestor->getParent();
        }

        return false;
    }
}
