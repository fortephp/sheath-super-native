<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules\Interaction;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\NativePhp\Rules\BaseRule;
use Forte\Sheath\NativePhp\Support\ElementCatalog;
use Forte\Sheath\NativePhp\Support\ElementEventCatalog;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleContext;

#[RequiresPackage('nativephp/mobile')]
class ModelTargetRule extends BaseRule
{
    /** @var array<string> */
    private const array NON_INPUT_ELEMENTS = [
        'column', 'row', 'stack', 'text', 'image', 'icon', 'spacer',
        'divider', 'scroll-view', 'pressable', 'canvas', 'rect', 'circle',
        'line', 'button', 'fab', 'refreshable', 'gesture-area',
        'top-bar', 'top-bar-action', 'top-bar-title', 'bottom-nav',
        'bottom-nav-item', 'side-nav', 'side-nav-group', 'side-nav-header',
        'side-nav-item', 'bottom-bar', 'virtual-list', 'webview',
    ];

    public function getId(): string
    {
        return 'native-model-on-non-input';
    }

    public function getDescription(): string
    {
        return 'Reports model bindings on elements and child components that cannot synchronize a value.';
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

            if (! $this->isLintableElement($element, includeComponents: true)) {
                return;
            }

            $isChildComponent = isset(ElementCatalog::componentTags()[$tag]);
            $canSynchronize = ElementEventCatalog::canDispatch($tag, 'change');
            if (! $isChildComponent
                && $canSynchronize !== false
                && ! ($canSynchronize === null && in_array($tag, self::NON_INPUT_ELEMENTS, true))) {
                return;
            }

            $attributes = $this->compilerEffectiveAttributes($element);
            if ($attributes === null) {
                return;
            }

            foreach ($attributes as $attribute) {
                $directive = $this->modelBindingDirective($attribute);

                if ($directive !== null && $this->modelBindingPropertyName($attribute) !== null) {
                    if ($isChildComponent) {
                        $context->report(
                            $element,
                            "{$directive} cannot bind child component <{$tag}>. Pass a prop and emit a custom event instead."
                        );

                        return;
                    }

                    $context->report(
                        $element,
                        "{$directive} cannot bind <{$tag}>. Use <text-input>, <toggle>, or <slider>."
                    );

                    return;
                }
            }
        });
    }
}
