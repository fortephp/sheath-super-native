<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules\Interaction;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\NativePhp\Support\ComponentViewMap;
use Forte\Sheath\NativePhp\Support\ElementCatalog;
use Forte\Sheath\NativePhp\Support\ElementEventCatalog;
use Forte\Sheath\NativePhp\Support\PrecompilerOracle;
use Forte\Sheath\NativePhp\Support\Suggestions;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleContext;
use Native\Mobile\Edge\CallbackRegistry;
use Throwable;

/** @phpstan-import-type ComponentMetadata from ComponentViewMap */
#[RequiresPackage('nativephp/mobile')]
class CallbackRule extends ComponentAwareRule
{
    /**
     * @var array<string>
     */
    private const array NON_METHOD_DIRECTIVES = ['navigate', 'model'];

    private const string METHOD_NAME_PATTERN = '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D';

    /**
     * @var array<string, int>
     */
    private const array EVENT_PAYLOAD_ARGS = [
        'press' => 0, 'longPress' => 0, 'doubleTap' => 0,
        'pressDown' => 0, 'pressUp' => 0, 'dismiss' => 0, 'refresh' => 0,
        'endReached' => 0, 'swipeDelete' => 0,
        'change' => 1, 'submit' => 1, 'swipe' => 1, 'pinchEnd' => 1,
        'selectionChange' => 3,
    ];

    public function getId(): string
    {
        return 'native-callback-exists';
    }

    /**
     * @param  ComponentMetadata  $owner
     */
    private function checkArity(RuleContext $context, ElementNode $element, array $owner, string $directive, string $method, string $value): void
    {
        $required = $owner['signatures'][$method] ?? null;
        $canonical = ElementEventCatalog::canonicalDirective($directive);
        $payload = self::EVENT_PAYLOAD_ARGS[$canonical] ?? null;

        if ($required === null || $payload === null || $required === 0) {
            return;
        }

        $bound = $this->boundArgumentCount($value);

        if ($bound === null || $bound + $payload >= $required) {
            return;
        }

        $componentFile = basename($owner['file']);
        $supplied = $bound + $payload;
        $requiredLabel = $required === 1 ? 'argument' : 'arguments';
        $suppliedLabel = $supplied === 1 ? 'argument' : 'arguments';

        $context->report(
            $element,
            "@{$directive}=\"{$value}\" provides {$supplied} {$suppliedLabel}, but {$method}() in {$componentFile} requires {$required} {$requiredLabel} ({$bound} bound, {$payload} from the event)."
        );
    }

    private function boundArgumentCount(string $value): ?int
    {
        $probe = (string) preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', '0', $value);

        if (str_contains($probe, '{{') || str_contains($probe, '{!!')) {
            return null;
        }

        $parsed = $this->parseCallback($probe);

        return is_array($parsed['args'] ?? null) ? count($parsed['args']) : null;
    }

    private function callbackDirective(Attribute $attribute, string $tag): ?string
    {
        $name = $attribute->nameText();
        if (! str_starts_with($name, '@')) {
            return null;
        }

        $directive = (string) strtok(substr($name, 1), '.(');
        if (in_array($directive, self::NON_METHOD_DIRECTIVES, true)) {
            return null;
        }

        $isArbitraryEvent = PrecompilerOracle::isArbitraryEventBinding($directive);
        $isChildComponent = isset(ElementCatalog::componentTags()[$tag]);

        if ($isArbitraryEvent !== $isChildComponent
            || ElementEventCatalog::canDispatch($tag, $directive) === false) {
            return null;
        }

        return $directive;
    }

    private function callbackMethod(string $value): ?string
    {
        $parsed = $this->parseCallback($value);

        return is_string($parsed['method'] ?? null) ? $parsed['method'] : null;
    }

    /**
     * @return array{method?: mixed, args?: mixed}|null
     */
    private function parseCallback(string $value): ?array
    {
        try {
            $registry = new CallbackRegistry;
            $parsed = $registry->resolve($registry->register($value));

            return is_array($parsed) ? $parsed : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  ComponentMetadata|null  $owner
     */
    private function checkAttribute(
        RuleContext $context,
        ElementNode $element,
        Attribute $attribute,
        ?array $owner,
    ): void {
        $name = $attribute->nameText();
        if (str_starts_with($name, '@')
            && ! str_starts_with($name, '@navigate')
            && ! str_starts_with($name, '@model')) {
            $directive = strtok($name, '.(');
            if ($this->hasCompilerIncompatibleUnquotedValue($attribute)) {
                $context->report($element, $this->unquotedValueMessage($directive));

                return;
            }
            if ($this->hasCompilerIncompatibleSeparator($attribute)) {
                $context->report($element, $this->separatorMessage($directive));

                return;
            }
        }

        $directive = $this->callbackDirective($attribute, $this->nativeTagName($element));
        $value = $attribute->valueText();

        if ($directive === null || $value === null || $attribute->isBound()) {
            return;
        }

        $method = $this->callbackMethod($value);
        if ($method === null) {
            return;
        }

        if ($method === '') {
            $context->report(
                $element,
                "@{$directive}=\"{$value}\" needs a component method name."
            );

            return;
        }

        if (preg_match(self::METHOD_NAME_PATTERN, $method) !== 1) {
            $context->report(
                $element,
                "@{$directive}=\"{$value}\" is an expression. Native events only call component methods; move this logic into one."
            );

            return;
        }

        if ($owner === null) {
            return;
        }

        if (isset($owner['public'][$method])) {
            $this->checkArity($context, $element, $owner, $directive, $method, $value);

            return;
        }

        $componentFile = basename($owner['file']);

        if (isset($owner['nonPublic'][$method])) {
            $context->report(
                $element,
                "@{$directive}=\"{$value}\" calls private method {$method}() in {$componentFile}. Make it public or protected."
            );

            return;
        }

        $context->report(
            $element,
            "@{$directive}=\"{$value}\" cannot find method {$method}() in {$componentFile}."
                .Suggestions::hint($method, array_keys($owner['public']))
        );
    }

    public function getDescription(): string
    {
        return 'Reports callbacks that cannot invoke an accessible component method or supply its required arguments.';
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

        $document->getElements()->each(function (ElementNode $element) use ($context, $owner): void {
            if (! $this->isLintableElement($element, includeComponents: true)) {
                return;
            }

            $attributes = $this->compilerEffectiveAttributes($element);
            if ($attributes === null) {
                return;
            }

            foreach ($this->attributesInRenderStructure($element) as $attribute) {
                if ($this->callbackDirective($attribute, $this->nativeTagName($element)) !== null
                    && $this->compilerAttributeKeys($attribute) === []) {
                    $attributes[] = $attribute;
                }
            }

            foreach ($attributes as $attribute) {
                $this->checkAttribute($context, $element, $attribute, $owner);
            }
        });
    }
}
