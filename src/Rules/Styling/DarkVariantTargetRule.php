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
class DarkVariantTargetRule extends BaseRule
{
    /**
     * @var array<string>
     */
    private const array DARK_WHITELIST = ['bg', 'borderColor', 'opacity', 'color', 'fontSize'];

    public function getId(): string
    {
        return 'native-dark-variant-target';
    }

    public function getDescription(): string
    {
        return 'Reports dark: utilities that NativePHP does not send to the device.';
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
            if (! str_contains($token, 'dark:')) {
                continue;
            }

            $hasDarkPayload = false;
            $isDiscarded = true;

            foreach (['ios', 'android'] as $platform) {
                $parsed = TailwindOracle::parseOn($platform, $token);

                if ($parsed === null || ! isset($parsed['dark']) || ! is_array($parsed['dark']) || $parsed['dark'] === []) {
                    continue;
                }

                $hasDarkPayload = true;
                $kept = array_intersect(array_keys($parsed['dark']), self::DARK_WHITELIST);

                if ($kept !== []) {
                    $isDiscarded = false;
                    break;
                }
            }

            if ($hasDarkPayload && $isDiscarded) {
                $context->report(
                    $element,
                    "'{$token}' has no dark-mode effect. dark: supports only background, text, border color, opacity, and font size."
                );
            }
        }
    }
}
