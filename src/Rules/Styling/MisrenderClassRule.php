<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules\Styling;

use Forte\Ast\Document\Document;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\NativePhp\Rules\BaseRule;
use Forte\Sheath\NativePhp\Support\ElementAttributeCatalog;
use Forte\Sheath\NativePhp\Support\TailwindOracle;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleContext;

#[RequiresPackage('nativephp/mobile')]
class MisrenderClassRule extends BaseRule
{
    /** @var array<string, string> reverse utility => forward utility */
    private const array REVERSE_FLEX_DIRECTIONS = [
        'flex-row-reverse' => 'flex-row',
        'flex-col-reverse' => 'flex-col',
    ];

    /**
     * @var array<string>
     */
    private const array LENGTH_PREFIXES = [
        'p', 'px', 'py', 'pt', 'pr', 'pb', 'pl',
        'm', 'mx', 'my', 'mt', 'mr', 'mb', 'ml',
        'gap', 'w', 'h', 'min-w', 'max-w', 'min-h', 'max-h',
        'top', 'right', 'bottom', 'left',
        'rounded', 'rounded-tl', 'rounded-tr', 'rounded-br', 'rounded-bl',
        'rounded-t', 'rounded-r', 'rounded-b', 'rounded-l', 'border',
    ];

    /**
     * @var array<string>
     */
    private const array FONT_SIZES = ['xs', 'sm', 'base', 'lg', 'xl', '2xl', '3xl', '4xl', '5xl', '6xl'];

    public function getId(): string
    {
        return 'native-misrender-class';
    }

    public function getDescription(): string
    {
        return 'Reports class values that NativePHP accepts but interprets incorrectly.';
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

        foreach ($this->staticClassTokenEntriesFor($document) as [$element, $token]) {
            $problem = $this->diagnose($token);

            if ($problem !== null) {
                $context->report($element, $problem);
            }
        }
    }

    private function diagnose(string $token): ?string
    {
        if (TailwindOracle::available() && TailwindOracle::classDoesNothing($token)) {
            return null;
        }

        preg_match('/^(?:(?:ios|android|dark):)+/', $token, $modifierMatch);
        $hasDarkVariant = str_contains($modifierMatch[0] ?? '', 'dark:');
        $bare = (string) preg_replace('/^(?:(?:ios|android|dark):)+/', '', $token);
        $negative = str_starts_with($bare, '-');
        $bare = ltrim($bare, '-');

        $forward = self::REVERSE_FLEX_DIRECTIONS[$bare] ?? null;
        if (! $hasDarkVariant
            && $forward !== null
            && $this->classesCompileToSameFlexDirection($bare, $forward)) {
            return "'{$token}' is parsed exactly like '{$forward}'; NativePHP sends no reverse-order signal. Reverse the children explicitly.";
        }

        if (preg_match('/^(.+?)-\[([^\]]+)\]$/', $bare, $m) !== 1) {
            if (preg_match('/^text-('.implode('|', self::FONT_SIZES).')\/\S+$/', $bare) === 1) {
                return "'{$token}' discards the line height; only the font size is used.";
            }

            return null;
        }

        [, $prefix, $value] = $m;

        if ($hasDarkVariant
            && ($prefix === 'aspect' || in_array($prefix, self::LENGTH_PREFIXES, true))) {
            return null;
        }

        if ($prefix === 'opacity') {
            if (! is_numeric($value) || ! is_finite((float) $value)) {
                return "'{$token}' becomes opacity ".(float) $value.'. Use a number from 0 to 1.';
            }

            if ((float) $value < 0) {
                return "'{$token}' sends invalid opacity {$value}. Use a value from 0 to 1.";
            }

            if ((float) $value > 1) {
                if ((float) $value > 100) {
                    return "'{$token}' sends invalid opacity {$value}. Use a value from 0 to 1.";
                }

                $normalized = (string) ((float) $value / 100);

                return "'{$token}' uses {$value}, not {$value}%. Use opacity-[{$normalized}].";
            }

            return null;
        }

        if ($prefix === 'text' && ! str_starts_with($value, '#') && ! $this->isPointValue($value)) {
            return "'{$token}' becomes font size ".(float) $value.'. Use a named size, finite number, px, or hex color.';
        }

        if ($prefix === 'border' && str_starts_with($value, '#')) {
            return null;
        }

        if (in_array($prefix, self::LENGTH_PREFIXES, true) && ! $this->isPointValue($value)) {
            $points = (float) $value;
            if ($negative) {
                $points *= -1;
            }

            return "'{$token}' becomes {$points} points. Use a finite number or px.";
        }

        if ($prefix === 'aspect' && ! $this->isPositiveRatio($value)) {
            return "'{$token}' is ignored. Use a positive number or W/H ratio.";
        }

        return null;
    }

    private function classesCompileToSameFlexDirection(string $reverse, string $forward): bool
    {
        if (! TailwindOracle::available()) {
            return false;
        }

        $capturedAttributes = ElementAttributeCatalog::capturedAttributes();
        if (isset($capturedAttributes['class'])) {
            return false;
        }

        foreach (['ios', 'android'] as $platform) {
            $reverseResult = TailwindOracle::parseOn($platform, $reverse);
            $forwardResult = TailwindOracle::parseOn($platform, $forward);

            if ($reverseResult === null || $forwardResult === null) {
                return false;
            }

            if ($reverseResult !== $forwardResult) {
                return false;
            }

            $direction = $reverseResult['flexDirection'] ?? null;
            if (! is_int($direction) && ! is_float($direction)) {
                return false;
            }
        }

        return true;
    }

    private function isPointValue(string $value): bool
    {
        $number = str_ends_with(strtolower($value), 'px') ? substr($value, 0, -2) : $value;

        return is_numeric($number) && is_finite((float) $number);
    }

    private function isPositiveRatio(string $value): bool
    {
        $parts = explode('/', $value);
        if (count($parts) > 2) {
            return false;
        }

        return array_all(
            $parts,
            static fn (string $part): bool => is_numeric($part)
                && is_finite((float) $part)
                && (float) $part > 0
        );
    }
}
