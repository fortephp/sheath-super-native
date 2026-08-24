<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules\Elements;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\NativePhp\Rules\BaseRule;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\Concerns\TraversesRenderedTree;
use Forte\Sheath\Rules\RuleContext;

#[RequiresPackage('nativephp/mobile')]
class StructureRule extends BaseRule
{
    use TraversesRenderedTree;

    /**
     * @var array<string, array<string>>
     */
    private const array REQUIRED_PARENTS = [
        'bottom-nav-item' => ['bottom-nav'],
        'top-bar-action' => ['top-bar', 'top-bar-action'],
        'top-bar-title' => ['top-bar'],
        'side-nav-header' => ['side-nav'],
        'side-nav-group' => ['side-nav'],
        'side-nav-item' => ['side-nav', 'side-nav-group'],
    ];

    public function getId(): string
    {
        return 'native-structure';
    }

    public function getDescription(): string
    {
        return 'Reports misplaced container items and virtual lists that cannot render rows.';
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

            $textAncestor = $this->renderedParentElement($element);

            while ($textAncestor !== null
                && ! in_array($this->nativeTagName($textAncestor), ['text', 'button'], true)) {
                $textAncestor = $this->renderedParentElement($textAncestor);
            }

            if ($textAncestor !== null) {
                return;
            }

            if ($tag === 'virtual-list') {
                if (! $this->everyAttributeRenderPathIsSatisfied(
                    $element,
                    'item',
                    self::virtualListItemMayRender(...),
                )) {
                    $context->report(
                        $element,
                        '<virtual-list> needs a non-empty item view; empty or "0" renders no rows.'
                    );
                }

                if (! $this->everyAttributeRenderPathIsSatisfied(
                    $element,
                    'count',
                    self::virtualListCountMayRender(...),
                )) {
                    $context->report(
                        $element,
                        '<virtual-list> needs a positive count; invalid values render no rows.'
                    );
                }

                return;
            }

            $allowed = self::REQUIRED_PARENTS[$tag] ?? null;

            if ($allowed === null) {
                return;
            }

            $parent = $this->renderedParentElement($element);

            if (! $parent instanceof ElementNode || ! in_array($this->nativeTagName($parent), $allowed, true)) {
                $context->report(
                    $element,
                    "<{$tag}> must be a direct child of <".implode('>/<', $allowed).'>; otherwise it is ignored.'
                );
            }
        });
    }

    private static function virtualListItemMayRender(Attribute $item): bool
    {
        if ($item->isDynamic() || $item->hasComplexValue()) {
            return true;
        }

        $value = $item->valueText();

        return $value !== null && trim($value) !== '' && trim($value) !== '0';
    }

    private static function virtualListCountMayRender(Attribute $count): bool
    {
        if ($count->isDynamic() || $count->hasComplexValue()) {
            return true;
        }

        return $count->valueText() === null || (int) $count->valueText() > 0;
    }
}
