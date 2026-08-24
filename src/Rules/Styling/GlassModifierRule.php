<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules\Styling;

use Forte\Ast\Document\Document;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\NativePhp\Rules\BaseRule;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleContext;

#[RequiresPackage('nativephp/mobile')]
class GlassModifierRule extends BaseRule
{
    /** @var array<string> */
    private const array MODIFIERS = ['prominent', 'interactive', 'clear'];

    public function getId(): string
    {
        return 'native-glass-modifier';
    }

    public function getDescription(): string
    {
        return 'Reports unrecognized glass modifiers that fall back to plain glass.';
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

        foreach ($this->staticClassTokenEntriesFor($document) as [$element, $token]) {
            $bare = (string) preg_replace('/^(?:ios|android):/', '', $token);

            if ($bare !== 'glass' && str_starts_with($bare, 'glass:')) {
                foreach (array_slice(explode(':', $bare), 1) as $segment) {
                    if (! in_array($segment, self::MODIFIERS, true)) {
                        $context->report(
                            $element,
                            "'{$token}' has unknown modifier '{$segment}' and falls back to plain glass. Use prominent, interactive, or clear."
                        );
                    }
                }
            }
        }
    }
}
