<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules\Elements;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\NativePhp\Rules\BaseRule;
use Forte\Sheath\NativePhp\Support\ElementAttributeCatalog;
use Forte\Sheath\NativePhp\Support\ElementEventCatalog;
use Forte\Sheath\NativePhp\Support\Suggestions;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleContext;

#[RequiresPackage('nativephp/mobile')]
class UnknownAttributeRule extends BaseRule
{
    /** @var array<string, string> */
    private const array INTERNAL_CALLBACK_ATTRIBUTES = [
        '_press' => 'press',
        '_longPress' => 'longPress',
        '_doubleTap' => 'doubleTap',
        '_pressDown' => 'pressDown',
        '_pressUp' => 'pressUp',
        '_change' => 'change',
        '_selectionChange' => 'selectionChange',
        '_submit' => 'submit',
        '_dismiss' => 'dismiss',
        '_refresh' => 'refresh',
        '_endReached' => 'endReached',
        '_swipeDelete' => 'swipeDelete',
        '_swipe' => 'swipe',
        '_pinchEnd' => 'pinchEnd',
        '_navigated' => 'navigated',
    ];

    public function getId(): string
    {
        return 'native-unknown-attribute';
    }

    /**
     * @param  array<string>  $accepted
     */
    private function canonicalSpelling(string $name, array $accepted): ?string
    {
        $normalized = strtolower(str_replace(['-', '_'], '', $name));

        foreach ($accepted as $candidate) {
            if (strtolower(str_replace(['-', '_'], '', $candidate)) === $normalized) {
                return $candidate;
            }
        }

        return null;
    }

    private function createRenameFix(Attribute $attribute, string $canonical, RuleContext $context): ?Fix
    {
        $rawName = $attribute->rawName();
        $start = $attribute->startOffset();

        if ($start < 0 || $rawName === '' || ! str_ends_with($rawName, $attribute->nameText())) {
            return null;
        }

        if ($context->getSourceAt($start, $start + strlen($rawName)) !== $rawName) {
            return null;
        }

        $prefix = substr($rawName, 0, strlen($rawName) - strlen($attribute->nameText()));

        return new Fix($start, $start + strlen($rawName), $prefix.$canonical);
    }

    private function handledElsewhere(Attribute $attribute, string $tag): bool
    {
        $name = $attribute->nameText();

        if ($name === ''
            || str_starts_with($name, '@')
            || $this->modelBindingDirective($attribute) !== null
            || $name === 'native:poll'
            || str_starts_with($name, 'native:poll.')
            || strtolower($name) === 'style'
            || $attribute->hasComplexName()
            || $name === '_navigate') {
            return true;
        }

        $directive = self::INTERNAL_CALLBACK_ATTRIBUTES[$name] ?? null;

        return $directive !== null
            && ElementEventCatalog::canDispatch($tag, $directive) !== false;
    }

    public function getDescription(): string
    {
        return 'Reports unsupported attributes and assignments the native compiler cannot parse correctly.';
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
            if ($this->elementHasUnmodelledAttributes($element)) {
                return;
            }

            $tag = $this->nativeTagName($element);

            if (! $this->isLintableElement($element)) {
                return;
            }

            $accepted = ElementAttributeCatalog::forTag($tag);

            foreach ($this->attributesInRenderStructure($element) as $attribute) {
                /** @var Attribute $attribute */
                $name = $attribute->nameText();

                if ($this->hasCompilerIncompatibleUnquotedValue($attribute)
                    && $this->genericRuleOwnsCompilerSyntaxDiagnostic($name, $context)) {
                    $context->report($element, $this->unquotedValueMessage($name));

                    continue;
                }

                if ($accepted === null) {
                    continue;
                }

                if ($this->hasCompilerIncompatibleSeparator($attribute)
                    && $this->genericRuleOwnsCompilerSyntaxDiagnostic($name, $context)) {
                    $context->report($element, $this->separatorMessage($name));

                    continue;
                }

                if ($this->handledElsewhere($attribute, $tag)) {
                    continue;
                }

                if ($name === 'key'
                    && $this->configuredRuleShouldReport($context, 'native-key-hygiene', Severity::WARNING)) {
                    continue;
                }

                if (in_array($name, $accepted, true)) {
                    continue;
                }

                $canonical = $this->canonicalSpelling($name, $accepted);

                if ($canonical !== null) {
                    $context->report(
                        $element,
                        "{$name} is ignored. Use '{$canonical}'.",
                        $this->createRenameFix($attribute, $canonical, $context)
                    );

                    continue;
                }

                $context->report(
                    $element,
                    "{$name} is not supported by <{$tag}> and will be ignored."
                        .Suggestions::hint($name, $accepted)
                );
            }
        });
    }

    private function genericRuleOwnsCompilerSyntaxDiagnostic(string $name, RuleContext $context): bool
    {
        if ($name === 'native:poll' || str_starts_with($name, 'native:poll.')) {
            return true;
        }

        $owner = match (true) {
            $name === '@model', str_starts_with($name, '@model.'),
            $name === 'native:model', str_starts_with($name, 'native:model.') => 'native-model-modifier',
            $name === 'native:key', $name === 'native-key', $name === 'key' => 'native-key-hygiene',
            str_starts_with($name, '@navigate') => 'native-unknown-navigate-transition',
            str_starts_with($name, '@') => 'native-callback-exists',
            default => null,
        };

        $defaultSeverity = $owner === 'native-key-hygiene'
            ? Severity::WARNING
            : Severity::ERROR;

        return $owner === null
            || ! $this->configuredRuleShouldReport($context, $owner, $defaultSeverity);
    }
}
