<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules\Styling;

use Forte\Ast\Document\Document;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\NativePhp\Rules\BaseRule;
use Forte\Sheath\NativePhp\Support\Theme\ThemeClass;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleContext;

#[RequiresPackage('nativephp/mobile')]
class PreferThemeTokensRule extends BaseRule
{
    public function getId(): string
    {
        return 'native-prefer-theme-tokens';
    }

    public function getDescription(): string
    {
        return 'Suggests theme tokens for hardcoded interface colors.';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::INFO;
    }

    public function check(Document $document, RuleContext $context): void
    {
        if (! $this->appliesTo($document, $context)) {
            return;
        }

        foreach ($this->staticClassTokenEntriesFor($document) as [$element, $token]) {
            if (ThemeClass::parse($token)?->fixedColor === true) {
                $context->report(
                    $element,
                    "'{$token}' is fixed. Consider a theme token for interface colors."
                );
            }
        }
    }
}
