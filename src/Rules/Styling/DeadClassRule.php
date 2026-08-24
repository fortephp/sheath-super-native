<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules\Styling;

use Forte\Ast\Document\Document;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\NativePhp\Rules\BaseRule;
use Forte\Sheath\NativePhp\Support\TailwindOracle;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleContext;

#[RequiresPackage('nativephp/mobile')]
class DeadClassRule extends BaseRule
{
    public function getId(): string
    {
        return 'native-dead-class';
    }

    public function getDescription(): string
    {
        return 'Reports class tokens unsupported by the native Tailwind subset.';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        if (! TailwindOracle::available() || ! $this->appliesTo($document, $context)) {
            return;
        }

        foreach ($this->staticClassTokenEntriesFor($document) as [$element, $token]) {
            if (TailwindOracle::classDoesNothing($token)) {
                $context->report(
                    $element,
                    "'{$token}' is unsupported and has no effect."
                );
            }
        }
    }
}
