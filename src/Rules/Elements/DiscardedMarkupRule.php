<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules\Elements;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\NativePhp\Rules\BaseRule;
use Forte\Sheath\NativePhp\Support\PrecompilerOracle;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleContext;

#[RequiresPackage('nativephp/mobile')]
class DiscardedMarkupRule extends BaseRule
{
    public function getId(): string
    {
        return 'native-discarded-markup';
    }

    public function getDescription(): string
    {
        return 'Reports bare tags and raw HTML that disappear from a native render.';
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

        $webviewRanges = [];

        $document->getElements()->each(function (ElementNode $element) use (&$webviewRanges): void {
            if (in_array($this->nativeTagName($element), ['webview', 'web-view'], true)
                && $this->usesNativeCompilerSyntax($element)) {
                $webviewRanges[] = [$element->startOffset(), $element->endOffset()];
            }
        });

        $document->getElements()->each(function (ElementNode $element) use ($context, $webviewRanges): void {
            $tag = $element->tagNameText();

            if ($this->isBlockedByComponentSlot($element)
                || str_starts_with($tag, 'native:')
                || str_starts_with($tag, 'x-')
                || $element->isComponent()) {
                return;
            }

            if (PrecompilerOracle::isCompiledAway($tag)) {
                return;
            }

            foreach ($webviewRanges as [$start, $end]) {
                if ($element->startOffset() > $start && $element->endOffset() <= $end) {
                    return;
                }
            }

            $context->report(
                $element,
                "<{$tag}> is not native. Its wrapper and attributes are discarded; native children still render."
            );
        });
    }
}
