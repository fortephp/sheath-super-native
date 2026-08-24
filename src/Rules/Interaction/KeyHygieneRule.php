<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules\Interaction;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\NativePhp\Rules\BaseRule;
use Forte\Sheath\NativePhp\Support\ElementCatalog;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\Concerns\ChecksLoopContext;
use Forte\Sheath\Rules\Concerns\DetectsExclusiveBranches;
use Forte\Sheath\Rules\Concerns\TraversesRenderedTree;
use Forte\Sheath\Rules\RuleContext;

#[RequiresPackage('nativephp/mobile')]
class KeyHygieneRule extends BaseRule
{
    use ChecksLoopContext;
    use DetectsExclusiveBranches;
    use TraversesRenderedTree;

    public function getId(): string
    {
        return 'native-key-hygiene';
    }

    public function getDescription(): string
    {
        return 'Reports missing, duplicate, or unstable keys and the wrong key syntax for child components.';
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

        $literalKeys = [];
        $componentTags = ElementCatalog::componentTags();

        $document->getElements()->each(function (ElementNode $element) use ($context, &$literalKeys, $componentTags): void {
            $this->checkElement($context, $element, $componentTags, $literalKeys);
        });
    }

    /**
     * @param  array<string, bool>  $componentTags
     * @param  array<string, array<string, list<ElementNode>>>  $literalKeys
     */
    private function checkElement(
        RuleContext $context,
        ElementNode $element,
        array $componentTags,
        array &$literalKeys,
    ): void {
        if (! $this->isLintableElement($element, includeComponents: true)
            || $this->elementHasUnmodelledAttributes($element)) {
            return;
        }

        $tag = $this->nativeTagName($element);
        $isChildComponent = isset($componentTags[$tag]);

        $attributes = $this->compilerEffectiveAttributes($element);
        if ($attributes === null) {
            return;
        }

        foreach ($this->attributesInRenderStructure($element) as $attribute) {
            if (in_array($attribute->nameText(), ['native:key', 'native-key', 'key'], true)
                && $this->compilerAttributeKeys($attribute) === []) {
                $attributes[] = $attribute;
            }
        }

        foreach ($attributes as $attribute) {
            $this->checkAttribute($context, $element, $attribute, $tag, $isChildComponent, $literalKeys);
        }

        $hasIdentityOnEveryPath = $this->hasIdentityOnEveryRenderPath($element);

        if (! $this->isInsideLoop($element) || $hasIdentityOnEveryPath !== false) {
            return;
        }

        if ($isChildComponent) {
            $context->report(
                $element,
                "<native:{$tag}> repeats in a loop without key=. Bind a stable ID (:key=\"\$row['id']\")."
            );

            return;
        }

        if ($this->isOutermostNativeElementInContainingLoop($element)) {
            $context->report(
                $element,
                "<{$tag}> repeats in a loop without native:key. Bind a stable ID (:native:key=\"\$row['id']\")."
            );
        }
    }

    private function hasIdentityOnEveryRenderPath(ElementNode $element): ?bool
    {
        $names = ['native:key', 'native-key', 'key'];

        if ($this->attributeRenderPathsNeedIndependentConditionCorrelation($element, $names)) {
            return null;
        }

        $paths = $this->explicitAttributeRenderPaths($element, $names, true);
        if ($paths === null) {
            return null;
        }

        return array_all(
            $paths,
            fn (array $path): bool => array_any(
                $path,
                fn (Attribute $attribute): bool => in_array($attribute->nameText(), $names, true),
            ),
        );
    }

    private function isOutermostNativeElementInContainingLoop(ElementNode $element): bool
    {
        $loop = $this->getContainingLoop($element);
        if ($loop === null) {
            return false;
        }

        $parent = $this->renderedParentElement($element);

        return ! $parent instanceof ElementNode || $this->getContainingLoop($parent) !== $loop;
    }

    /**
     * @param  array<string, array<string, list<ElementNode>>>  $literalKeys
     */
    private function checkAttribute(
        RuleContext $context,
        ElementNode $element,
        Attribute $attribute,
        string $tag,
        bool $isChildComponent,
        array &$literalKeys,
    ): void {
        $name = $attribute->nameText();

        if (! $isChildComponent && $name === 'key') {
            $context->report(
                $element,
                "Use native:key on native element <{$tag}>; key= is only for child components."
            );

            return;
        }

        if ($isChildComponent && in_array($name, ['native:key', 'native-key'], true)) {
            $context->report(
                $element,
                "Use key= on child component <{$tag}>; native:key is only for native elements."
            );

            return;
        }

        $keyNames = $isChildComponent ? ['key'] : ['native:key', 'native-key'];
        if (! in_array($name, $keyNames, true)) {
            return;
        }

        if ($this->hasCompilerIncompatibleUnquotedValue($attribute)) {
            $context->report($element, $this->unquotedValueMessage($name));

            return;
        }

        if ($this->hasCompilerIncompatibleSeparator($attribute)) {
            $context->report($element, $this->separatorMessage($name));

            return;
        }

        $value = $attribute->valueText();
        if ($value === null) {
            $context->report($element, "{$name} needs a value that identifies this item.");

            return;
        }

        if ($this->usesContainingLoopPosition($element, $value)) {
            $context->report(
                $element,
                "{$name} uses the loop position, which breaks identity after reordering. Bind a stable ID such as \$row['id']."
            );

            return;
        }

        if ($attribute->isBound() || $attribute->hasComplexValue()) {
            return;
        }

        if ($this->isInsideLoop($element)) {
            $binding = $isChildComponent ? ':key' : ':native:key';
            $context->report(
                $element,
                "{$name}=\"{$value}\" repeats in every loop iteration. Bind a unique ID ({$binding}=\"\$row['id']\")."
            );

            return;
        }

        $scope = $isChildComponent
            ? 'component:'.$tag
            : $this->nativeElementKeyScope($element);

        foreach ($literalKeys[$scope][$value] ?? [] as $earlier) {
            if ($earlier === $element || $this->nodesAreMutuallyExclusive($earlier, $element)) {
                continue;
            }

            $message = $isChildComponent
                ? "{$name}=\"{$value}\" duplicates another <{$tag}> key. Use a unique key."
                : "{$name}=\"{$value}\" is duplicated in this key scope. Use a unique key.";

            $context->report($element, $message);

            break;
        }

        $literalKeys[$scope][$value][] = $element;
    }

    private function usesContainingLoopPosition(ElementNode $element, string $value): bool
    {
        $loop = $this->getContainingLoop($element);
        if ($loop === null) {
            return false;
        }

        if (preg_match('/\$loop\s*\??->\s*(?:index|iteration)\b/', $value) === 1) {
            return true;
        }

        $arguments = $loop->arguments() ?? '';
        $positionVariable = null;

        if ($loop->isDirectiveNamed('for')
            && preg_match('/^\s*\(?\s*\$([A-Za-z_][A-Za-z0-9_]*)\s*=/', $arguments, $match) === 1) {
            $positionVariable = $match[1];
        }

        return $positionVariable !== null
            && preg_match('/\$'.preg_quote($positionVariable, '/').'\b/', $value) === 1;
    }

    private function nativeElementKeyScope(ElementNode $element): string
    {
        $ancestor = $this->renderedParentElement($element);

        while ($ancestor instanceof ElementNode) {
            if ($this->elementHasUnmodelledAttributes($ancestor)) {
                return 'opaque:'.spl_object_id($ancestor);
            }

            $paths = $this->compilerEffectiveAttributeRenderPaths($ancestor);
            if ($paths === null) {
                return 'opaque:'.spl_object_id($ancestor);
            }

            $guaranteedKey = $paths !== [];
            foreach ($paths as $path) {
                $pathHasKey = false;
                foreach ($path as $attribute) {
                    if ($this->attributeMatchesName($attribute, ['native:key', 'native-key'])
                        && $attribute->valueText() !== null) {
                        $pathHasKey = true;
                        break;
                    }
                }

                if (! $pathHasKey) {
                    $guaranteedKey = false;
                    break;
                }
            }

            if ($guaranteedKey) {
                return 'keyed:'.spl_object_id($ancestor);
            }

            $ancestor = $this->renderedParentElement($ancestor);
        }

        return 'root';
    }
}
