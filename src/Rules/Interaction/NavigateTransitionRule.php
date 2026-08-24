<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules\Interaction;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\NativePhp\Rules\BaseRule;
use Forte\Sheath\NativePhp\Support\Suggestions;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleContext;
use Native\Mobile\Edge\NativeTagPrecompiler;
use ReflectionClassConstant;
use Throwable;

#[RequiresPackage('nativephp/mobile')]
class NavigateTransitionRule extends BaseRule
{
    /**
     * @var array<string>
     */
    private const array NAV_TYPES = ['back', 'replace', 'exitToWeb'];

    /**
     * @var array<string>
     */
    private const array KNOWN_TRANSITIONS = [
        'fade', 'slideFromRight', 'slideFromLeft', 'slideFromBottom',
        'fadeFromBottom', 'scaleFromCenter', 'parallaxPush', 'none',
    ];

    public function getId(): string
    {
        return 'native-unknown-navigate-transition';
    }

    public function getDescription(): string
    {
        return 'Reports unrecognized @navigate modifiers that NativePHP ignores.';
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

        $legal = array_merge($this->transitionNames(), self::NAV_TYPES);

        $document->getElements()->each(function (ElementNode $element) use ($context, $legal): void {
            $tag = $this->nativeTagName($element);
            if (! $this->isLintableElement($element)) {
                return;
            }

            $attributes = $this->compilerEffectiveAttributes($element);
            if ($attributes === null) {
                return;
            }

            foreach ($this->attributesInRenderStructure($element) as $attribute) {
                if (str_starts_with($attribute->nameText(), '@navigate')
                    && $this->compilerAttributeKeys($attribute) === []) {
                    $attributes[] = $attribute;
                }
            }

            foreach ($attributes as $attribute) {
                $name = $attribute->nameText();

                if (str_starts_with($name, '@navigate')
                    && $this->hasCompilerIncompatibleUnquotedValue($attribute)) {
                    $context->report($element, $this->unquotedValueMessage(strtok($name, '.(')));

                    continue;
                }

                if (str_starts_with($name, '@navigate')
                    && $this->hasCompilerIncompatibleSeparator($attribute)) {
                    $context->report($element, $this->separatorMessage(strtok($name, '.(')));

                    continue;
                }

                if (! str_starts_with($name, '@navigate.')) {
                    continue;
                }

                $modifiers = strtok(substr($name, strlen('@navigate.')), '(');

                foreach (explode('.', (string) $modifiers) as $modifier) {
                    if ($modifier !== '' && ! in_array($modifier, $legal, true)) {
                        $closest = Suggestions::closest($modifier, $legal);
                        $message = "Unknown @navigate modifier '{$modifier}'.";

                        if ($closest === null) {
                            $message .= ' Valid: '.implode(', ', $legal).'.';
                        }

                        $context->report(
                            $element,
                            $message.Suggestions::hintFor($closest),
                            $closest === null ? null : $this->createModifierFix($context, $attribute, $modifier, $closest)
                        );
                    }
                }
            }
        });
    }

    private function createModifierFix(RuleContext $context, Attribute $attribute, string $modifier, string $closest): ?Fix
    {
        $start = $attribute->startOffset();
        $rawName = $attribute->rawName();
        $position = strpos($rawName, '.'.$modifier);

        if ($start < 0 || $position === false) {
            return null;
        }

        $from = $start + $position + 1;
        $to = $from + strlen($modifier);

        if ($context->getSourceAt($from, $to) !== $modifier) {
            return null;
        }

        return new Fix($from, $to, $closest);
    }

    /**
     * @return array<string>
     */
    private function transitionNames(): array
    {
        if (class_exists(NativeTagPrecompiler::class)) {
            try {
                $constant = new ReflectionClassConstant(
                    NativeTagPrecompiler::class,
                    'NAVIGATE_TRANSITIONS'
                );

                /** @var array<string, string> $value */
                $value = $constant->getValue();

                if ($value !== []) {
                    return array_keys($value);
                }
            } catch (Throwable) {
            }
        }

        return self::KNOWN_TRANSITIONS;
    }
}
