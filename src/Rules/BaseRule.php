<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Parser\ParserOptions;
use Forte\Sheath\Contracts\ProvidesRuleDocument;
use Forte\Sheath\Contracts\SharesCacheContext;
use Forte\Sheath\NativePhp\Support\ElementCatalog;
use Forte\Sheath\NativePhp\Support\ElementEventCatalog;
use Forte\Sheath\NativePhp\Support\NativeSyntaxDocument;
use Forte\Sheath\NativePhp\Support\PrecompilerOracle;
use Forte\Sheath\NativePhp\Support\RuntimeContext;
use Forte\Sheath\NativePhp\Support\Styling\StaticClassList;
use Forte\Sheath\NativePhp\Support\ViewDetector;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

abstract class BaseRule extends AbstractRule implements ProvidesRuleDocument, SharesCacheContext
{
    use DetectsOpaqueAttributes;

    protected array $options = [
        'nativeViewPaths' => ['views/native/'],
    ];

    public function cacheContext(array $options): array|string
    {
        return RuntimeContext::fingerprint();
    }

    public function cacheContextGroup(array $options): string
    {
        return 'nativephp:runtime';
    }

    public function ruleDocumentKey(): string
    {
        return 'nativephp:compiler-tag-syntax';
    }

    public function ruleDocument(Document $document, ParserOptions $parserOptions): Document
    {
        return NativeSyntaxDocument::from($document, $parserOptions);
    }

    public function getCategory(): RuleCategory|string
    {
        return 'native';
    }

    protected function isNativeView(Document $document, string $filePath): bool
    {
        /** @var array<string> $paths */
        $paths = (array) $this->getOption('nativeViewPaths', ['views/native/']);
        $pathsWereConfigured = array_key_exists('nativeViewPaths', $this->getConfiguredOptions());

        return ViewDetector::isNativeView($document, $filePath, $paths, ! $pathsWereConfigured);
    }

    protected function targetsSupportedCompiler(RuleContext $context): bool
    {
        if (! $this->hasPackage($context, 'nativephp/mobile')) {
            return true;
        }

        $version = $this->getPackageVersion($context, 'nativephp/mobile');

        if ($version === null || str_contains($version, 'dev')) {
            return true;
        }

        if (! preg_match('/^v?\d+(\.\d+)*([-+][0-9A-Za-z.\-]+)?$/', $version)) {
            return true;
        }

        return $this->packageSatisfies($context, 'nativephp/mobile', '^4.0');
    }

    protected function appliesTo(Document $document, RuleContext $context): bool
    {
        return $this->targetsSupportedCompiler($context)
            && $this->isNativeView($document, $context->getFilePath());
    }

    protected function nativeTagName(ElementNode $element): string
    {
        $prefixed = $this->nativePrefixedTagName($element);
        if ($prefixed !== null) {
            return strtolower($prefixed);
        }

        $tag = strtolower($element->tagNameText());

        return str_starts_with($tag, 'native:') ? substr($tag, 7) : $tag;
    }

    protected function isLintableElement(ElementNode $element, bool $includeComponents = false): bool
    {
        $tag = $this->nativeTagName($element);

        return ElementCatalog::isKnownTag($tag)
            && $this->usesNativeCompilerSyntax($element)
            && ($includeComponents || ! isset(ElementCatalog::componentTags()[$tag]))
            && ! $this->isBlockedByComponentSlot($element);
    }

    protected function usesNativeCompilerSyntax(ElementNode $element): bool
    {
        if ($this->nativePrefixedTagName($element) !== null) {
            return true;
        }

        $tag = strtolower($element->tagNameText());

        return str_starts_with($tag, 'native:')
            || PrecompilerOracle::isCompiledAway($tag);
    }

    protected function nativePrefixedTagName(ElementNode $element): ?string
    {
        $start = $element->startOffset();
        if ($start < 0) {
            return null;
        }

        return preg_match(
            '/\G<\s*native\s*:\s*([A-Za-z0-9_-]+)/A',
            $element->getDocument()->source(),
            $match,
            0,
            $start,
        ) === 1
            ? $match[1]
            : null;
    }

    protected function isBlockedByComponentSlot(ElementNode $element): bool
    {
        $ancestor = $element->getParent();
        while ($ancestor !== null) {
            if ($ancestor instanceof ElementNode
                && isset(ElementCatalog::componentTags()[$this->nativeTagName($ancestor)])
                && $this->usesNativeCompilerSyntax($ancestor)) {
                return true;
            }

            $ancestor = $ancestor->getParent();
        }

        return false;
    }

    protected function modelBindingDirective(Attribute $attribute): ?string
    {
        $name = $attribute->nameText();

        if ($name === '@model') {
            return '@model';
        }

        return $name === 'native:model' || str_starts_with($name, 'native:model.')
            ? 'native:model'
            : null;
    }

    protected function hasCompilerIncompatibleSeparator(Attribute $attribute): bool
    {
        $rawName = $attribute->rawName();
        if ($rawName === '') {
            return false;
        }

        $source = $attribute->getDocument()->getText(
            $attribute->startOffset(),
            $attribute->endOffset(),
        );

        return preg_match(
            '/^'.preg_quote($rawName, '/').'(?:\s+=|=\s+)/s',
            $source,
        ) === 1;
    }

    protected function separatorMessage(string $directive): string
    {
        return "{$directive} cannot contain whitespace around `=`; NativePHP will not compile it as a directive.";
    }

    protected function hasCompilerIncompatibleUnquotedValue(Attribute $attribute): bool
    {
        $source = $attribute->getDocument()->getText(
            $attribute->startOffset(),
            $attribute->endOffset(),
        );
        $equals = strpos($source, '=');
        if ($equals === false) {
            return false;
        }

        $value = ltrim(substr($source, $equals + 1));

        return $value === '' || ! in_array($value[0], ['"', "'"], true);
    }

    protected function unquotedValueMessage(string $attribute): string
    {
        return "{$attribute} must use a single- or double-quoted value; NativePHP does not compile unquoted assignments correctly.";
    }

    protected function modelBindingPropertyName(Attribute $attribute): ?string
    {
        if ($this->modelBindingDirective($attribute) === null
            || $attribute->isBound()
            || $attribute->hasComplexValue()) {
            return null;
        }

        $property = $attribute->valueText();

        return $property !== null
            && preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D', $property) === 1
                ? $property
                : null;
    }

    /**
     * @return array<string>
     */
    protected function staticClassTokens(Attribute $attribute): array
    {
        return StaticClassList::tokens($attribute);
    }

    /**
     * @return list<array{token: string, attribute: Attribute}>|null
     */
    protected function staticClassTokenEntries(ElementNode $element): ?array
    {
        $paths = $this->staticClassAttributePaths($element);
        if ($paths === null) {
            return null;
        }

        $entries = [];
        foreach ($paths as $path) {
            foreach ($path as $attribute) {
                foreach ($this->staticClassTokens($attribute) as $token) {
                    $entries[$token] ??= ['token' => $token, 'attribute' => $attribute];
                }
            }
        }

        return array_values($entries);
    }

    /** @return iterable<array{0: ElementNode, 1: string, 2: Attribute}> */
    protected function staticClassTokenEntriesFor(Document $document): iterable
    {
        foreach ($document->getElements() as $element) {
            foreach ($this->staticClassTokenEntries($element) ?? [] as $entry) {
                yield [$element, $entry['token'], $entry['attribute']];
            }
        }
    }

    /**
     * @return list<list<Attribute>>|null
     */
    private function staticClassAttributePaths(ElementNode $element): ?array
    {
        if (! $this->isLintableElement($element) || $this->elementHasUnmodelledAttributes($element)) {
            return null;
        }

        $paths = $this->compilerEffectiveAttributeRenderPaths($element);
        if ($paths === null) {
            return null;
        }

        $classPaths = [];

        foreach ($paths as $path) {
            $classAttributes = [];

            foreach ($path as $attribute) {
                if (! $this->attributeMatchesName($attribute, 'class')) {
                    continue;
                }

                if ($attribute->isBound() || ! $attribute->isStatic() || $attribute->hasComplexValue()) {
                    return null;
                }

                $classAttributes[] = $attribute;
            }

            $classPaths[] = $classAttributes;
        }

        return $classPaths;
    }

    /**
     * Flatten the effective attributes from every known render path.
     *
     * @param  callable(Attribute): list<string>  $compiledKeys
     * @return list<Attribute>|null
     */
    protected function compilerEffectiveAttributes(
        ElementNode $element,
        ?callable $compiledKeys = null,
    ): ?array {
        $paths = $this->compilerEffectiveAttributeRenderPaths($element, $compiledKeys);
        if ($paths === null) {
            return null;
        }

        $effective = [];

        foreach ($paths as $path) {
            foreach ($path as $attribute) {
                $effective[$attribute->startOffset()] = $attribute;
            }
        }

        return array_values($effective);
    }

    /**
     * Return the compiler-effective attribute sequence for every explicit
     * opening-tag render path. NativePHP emits attributes into a PHP array,
     * where a later write replaces an earlier write to the same key.
     *
     * @param  (callable(Attribute): list<string>)|null  $compiledKeys
     * @return list<list<Attribute>>|null
     */
    protected function compilerEffectiveAttributeRenderPaths(
        ElementNode $element,
        ?callable $compiledKeys = null,
    ): ?array {
        if ($this->elementHasUnmodelledAttributes($element)) {
            return null;
        }

        $paths = $this->explicitAttributeRenderPaths($element);
        if ($paths === null) {
            return null;
        }

        $compiledKeys ??= $this->compilerAttributeKeys(...);
        $effectivePaths = [];

        foreach ($paths as $path) {
            $writes = [];

            foreach ($path as $attribute) {
                foreach ($compiledKeys($attribute) as $key) {
                    $writes[$key] = $attribute;
                }
            }

            $effective = [];
            foreach ($writes as $attribute) {
                $effective[$attribute->startOffset()] = $attribute;
            }
            $effectivePaths[] = array_values($effective);
        }

        return $effectivePaths;
    }

    /** @return list<string> */
    protected function compilerAttributeKeys(Attribute $attribute): array
    {
        $name = $attribute->nameText();

        if ($this->modelBindingDirective($attribute) !== null) {
            return $this->compilerDirectiveIsMalformed($attribute)
                ? []
                : ['value', '_change', 'sync-mode'];
        }

        if (str_starts_with($name, '@navigate')) {
            return $this->compilerDirectiveIsMalformed($attribute) ? [] : ['_navigate'];
        }

        if (str_starts_with($name, '@')) {
            if ($this->compilerDirectiveIsMalformed($attribute)) {
                return [];
            }

            $directive = (string) strtok(substr($name, 1), '.(');
            if ($directive === '') {
                return [];
            }

            $canonical = ElementEventCatalog::canonicalDirective($directive);

            return PrecompilerOracle::isArbitraryEventBinding($directive)
                ? ['_event-'.$directive]
                : ['_'.$canonical];
        }

        if ($name === 'native:key') {
            return $this->compilerDirectiveIsMalformed($attribute) ? [] : ['native-key'];
        }

        return [$name];
    }

    private function compilerDirectiveIsMalformed(Attribute $attribute): bool
    {
        return $this->hasCompilerIncompatibleSeparator($attribute)
            || $this->hasCompilerIncompatibleUnquotedValue($attribute);
    }
}
