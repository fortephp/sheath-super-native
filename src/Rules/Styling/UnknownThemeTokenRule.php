<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules\Styling;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\NativePhp\Rules\BaseRule;
use Forte\Sheath\NativePhp\Support\Suggestions;
use Forte\Sheath\NativePhp\Support\TailwindOracle;
use Forte\Sheath\NativePhp\Support\Theme\ThemeClass;
use Forte\Sheath\NativePhp\Support\Theme\ThemeTokenSource;
use Forte\Sheath\NativePhp\Support\Theme\ThemeTokenStatus;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleContext;

#[RequiresPackage('nativephp/mobile')]
class UnknownThemeTokenRule extends BaseRule
{
    public function getId(): string
    {
        return 'native-unknown-theme-token';
    }

    public function getDescription(): string
    {
        return 'Reports theme tokens missing from the application theme source.';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $source = ThemeTokenSource::current();

        if (! $source->canVerify() || ! $this->appliesTo($document, $context)) {
            return;
        }

        $tokens = $source->tokens();

        foreach ($this->staticClassTokenEntriesFor($document) as [$element, $class, $classAttr]) {
            $themeClass = ThemeClass::parse($class);
            if ($themeClass?->token === null) {
                continue;
            }

            if (TailwindOracle::classDoesNothing($class)) {
                continue;
            }

            $status = $source->status($class, $themeClass->token);

            if ($status === ThemeTokenStatus::Resolved || $status === ThemeTokenStatus::Unverifiable) {
                continue;
            }

            if ($status === ThemeTokenStatus::UndefinedRuntime) {
                $context->report(
                    $element,
                    "'{$class}' uses undefined runtime theme token '{$themeClass->token}' and has no effect."
                );

                continue;
            }

            $closest = Suggestions::closest($themeClass->token, $tokens);
            $message = $status === ThemeTokenStatus::MissingSource
                ? "'{$class}' cannot resolve: no runtime or native-ui.theme tokens are configured."
                : "'{$class}' uses undefined theme token '{$themeClass->token}' and has no effect."
                    .Suggestions::hintFor($closest);

            $context->report(
                $element,
                $message,
                $closest === null ? null : $this->createTokenFix($context, $classAttr, $class, $themeClass->token, $closest)
            );
        }
    }

    private function createTokenFix(RuleContext $context, Attribute $classAttr, string $class, string $token, string $closest): ?Fix
    {
        $start = $classAttr->startOffset();
        $end = $classAttr->endOffset();

        if ($start < 0 || $end <= $start) {
            return null;
        }

        $source = $context->getSourceAt($start, $end);
        $position = strpos($source, $class);

        if ($position === false || str_contains(substr($source, $position + 1), $class)) {
            return null;
        }

        return new Fix(
            $start + $position,
            $start + $position + strlen($class),
            str_replace('theme-'.$token, 'theme-'.$closest, $class)
        );
    }
}
