<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules\Styling;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\NativePhp\Rules\BaseRule;
use Forte\Sheath\NativePhp\Support\TailwindOracle;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleContext;

#[RequiresPackage('nativephp/mobile')]
class BorderPairRule extends BaseRule
{
    public function getId(): string
    {
        return 'native-border-requires-pair';
    }

    public function getDescription(): string
    {
        return 'Reports borders missing a width or color, and theme-border ordering that resets an explicit width.';
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

        $document->getElements()->each(function (ElementNode $element) use ($context): void {
            if (! $this->isLintableElement($element)) {
                return;
            }

            $names = ['class', 'borderWidth', 'borderColor'];

            if ($this->elementHasUnmodelledAttributes($element)
                || $this->attributeRenderPathsNeedIndependentConditionCorrelation($element, $names)) {
                return;
            }

            $paths = $this->explicitAttributeRenderPaths($element, $names);
            if ($paths === null) {
                return;
            }

            $messages = [];
            foreach ($paths as $path) {
                $tokens = [];
                $hasAttributeWidth = false;
                $hasAttributeColor = false;

                foreach ($path as $attribute) {
                    if ($this->attributeMatchesName($attribute, 'borderWidth')) {
                        $hasAttributeWidth = true;
                    } elseif ($this->attributeMatchesName($attribute, 'borderColor')) {
                        $hasAttributeColor = true;
                    } elseif ($this->attributeMatchesName($attribute, 'class')) {
                        if ($attribute->isBound() || ! $attribute->isStatic() || $attribute->hasComplexValue()) {
                            return;
                        }

                        array_push($tokens, ...$this->staticClassTokens($attribute));
                    }
                }

                foreach ($this->messagesForPath($tokens, $hasAttributeWidth, $hasAttributeColor) as $message) {
                    $messages[$message] = true;
                }
            }

            foreach (array_keys($messages) as $message) {
                $context->report($element, $message);
            }
        });
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function messagesForPath(array $tokens, bool $hasAttributeWidth, bool $hasAttributeColor): array
    {
        /** @var array<string, array{width: bool, color: bool, explicitWidth: mixed, themeAfterWidth: ?string}> $states */
        $states = [
            'ios' => ['width' => false, 'color' => false, 'explicitWidth' => null, 'themeAfterWidth' => null],
            'android' => ['width' => false, 'color' => false, 'explicitWidth' => null, 'themeAfterWidth' => null],
        ];

        foreach ($tokens as $token) {
            foreach (array_keys($states) as $platform) {
                $parsed = TailwindOracle::parseOn($platform, $token) ?? [];
                if ($parsed === []) {
                    continue;
                }

                $bare = (string) preg_replace('/^(?:ios|android):/', '', $token);
                if (str_starts_with($bare, 'border-theme-')) {
                    if (is_numeric($states[$platform]['explicitWidth'])
                        && (float) $states[$platform]['explicitWidth'] !== 1.0) {
                        $states[$platform]['themeAfterWidth'] = $token;
                    }
                    $states[$platform]['width'] = $states[$platform]['color'] = true;

                    continue;
                }

                if (array_key_exists('borderWidth', $parsed)) {
                    $states[$platform]['width'] = true;
                    $states[$platform]['explicitWidth'] = $parsed['borderWidth'];
                    $states[$platform]['themeAfterWidth'] = null;
                }
                if (array_key_exists('borderColor', $parsed)) {
                    $states[$platform]['color'] = true;
                }
            }
        }

        // The collector parses class first, then merges literal/bound attributes
        // over the parsed values. Model that final precedence here as well.
        foreach (array_keys($states) as $platform) {
            if ($hasAttributeWidth) {
                $states[$platform]['width'] = true;
                $states[$platform]['explicitWidth'] = null;
                $states[$platform]['themeAfterWidth'] = null;
            }
            if ($hasAttributeColor) {
                $states[$platform]['color'] = true;
            }
        }

        $messages = [];
        $themeAfterWidth = [];
        $widthOnly = [];
        $colorOnly = [];
        foreach ($states as $platform => $state) {
            if ($state['themeAfterWidth'] !== null) {
                $themeAfterWidth[$state['themeAfterWidth']] = true;
            }
            if ($state['width'] && ! $state['color']) {
                $widthOnly[] = $platform;
            }
            if ($state['color'] && ! $state['width']) {
                $colorOnly[] = $platform;
            }
        }

        if ($themeAfterWidth !== []) {
            $theme = array_key_first($themeAfterWidth);
            $messages[] = "'{$theme}' resets the earlier border width to 1. Put the theme class before the width class.";
        }
        if ($widthOnly !== []) {
            $messages[] = 'A border width without a border color renders no border'.self::platformSuffix($widthOnly).'. Add a border color.';
        }
        if ($colorOnly !== []) {
            $messages[] = 'A border color without a border width renders no border'.self::platformSuffix($colorOnly).'. Add border or border-N to set the width.';
        }

        return $messages;
    }

    /** @param array<string> $platforms */
    private static function platformSuffix(array $platforms): string
    {
        return count($platforms) === 2 ? '' : ' on '.$platforms[0];
    }
}
