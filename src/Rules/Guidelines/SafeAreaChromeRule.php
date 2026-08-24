<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules\Guidelines;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\NativePhp\Rules\BaseRule;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\Concerns\DetectsExclusiveBranches;
use Forte\Sheath\Rules\Concerns\TraversesRenderedTree;
use Forte\Sheath\Rules\RuleContext;

#[RequiresPackage('nativephp/mobile')]
class SafeAreaChromeRule extends BaseRule
{
    use DetectsExclusiveBranches;
    use TraversesRenderedTree;

    /** @var array<string> */
    private const array SAFE_AREA_CLASSES = ['safe-area', 'safe-area-top', 'safe-area-bottom'];

    /** @var array<string, array<string>> */
    private const array CHROME_EDGES = [
        'top-bar' => ['top'],
        'bottom-nav' => ['bottom'],
        'side-nav' => ['top', 'bottom'],
        'bottom-bar' => ['bottom'],
    ];

    public function getId(): string
    {
        return 'native-safe-area-with-chrome';
    }

    public function getDescription(): string
    {
        return 'Reports safe-area classes that duplicate native chrome insets.';
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

        /** @var array<string, list<ElementNode>> $chromeByEdge */
        $chromeByEdge = [];
        /** @var list<ElementNode> $nativeChromeTriggers */
        $nativeChromeTriggers = [];

        $document->getElements()->each(function (ElementNode $element) use (&$chromeByEdge, &$nativeChromeTriggers): void {
            $tag = $this->nativeTagName($element);
            $edges = $this->isLintableElement($element)
                ? self::CHROME_EDGES[$tag] ?? null
                : null;

            if ($edges !== null
                && $this->isHoistableChrome($element)
                && ! $this->isCustomChrome($element)) {
                if (in_array($tag, ['top-bar', 'bottom-nav'], true)) {
                    $nativeChromeTriggers[] = $element;
                }

                foreach ($edges as $edge) {
                    $chromeByEdge[$edge][] = $element;
                }
            }
        });

        if (isset($chromeByEdge['bottom'])) {
            $chromeByEdge['bottom'] = array_values(array_filter(
                $chromeByEdge['bottom'],
                fn (ElementNode $chrome): bool => $this->nativeTagName($chrome) !== 'bottom-bar'
                    || $this->bottomBarGetsNativeInset($chrome, $nativeChromeTriggers),
            ));

            if ($chromeByEdge['bottom'] === []) {
                unset($chromeByEdge['bottom']);
            }
        }

        if ($chromeByEdge === []) {
            return;
        }

        foreach ($this->staticClassTokenEntriesFor($document) as [$element, $token]) {
            $platform = null;
            $safeArea = $token;
            if (preg_match('/^(ios|android):(safe-area(?:-top|-bottom)?)$/', $token, $match) === 1) {
                $platform = $match[1];
                $safeArea = $match[2];
            }

            if (in_array($safeArea, self::SAFE_AREA_CLASSES, true)
                && $this->conflictsWithChrome($element, $safeArea, $chromeByEdge)) {
                $context->report(
                    $element,
                    "'{$token}' duplicates an inset already provided by native chrome"
                        .($platform === null ? '' : " on {$platform}").'.'
                );
            }
        }
    }

    private function isHoistableChrome(ElementNode $element): bool
    {
        $parent = $this->renderedParentElement($element);

        return $parent === null || $this->renderedParentElement($parent) === null;
    }

    /** @param list<ElementNode> $nativeChromeTriggers */
    private function bottomBarGetsNativeInset(ElementNode $bottomBar, array $nativeChromeTriggers): bool
    {
        return array_any(
            $nativeChromeTriggers,
            fn (ElementNode $trigger): bool => ! $this->nodesAreMutuallyExclusive($bottomBar, $trigger),
        );
    }

    private function isCustomChrome(ElementNode $element): bool
    {
        if ($this->elementHasUnmodelledAttributes($element)) {
            return true;
        }

        $paths = $this->explicitAttributeRenderPaths($element, 'custom', true);
        if ($paths === null) {
            return true;
        }

        foreach ($paths as $path) {
            $custom = null;
            foreach ($path as $attribute) {
                if ($this->attributeMatchesName($attribute, 'custom')) {
                    $custom = $attribute;
                    break;
                }
            }

            if (! $custom instanceof Attribute || ! self::customAttributeIsTruthy($custom)) {
                return false;
            }
        }

        return true;
    }

    private static function customAttributeIsTruthy(Attribute $custom): bool
    {
        if ($custom->hasComplexValue()) {
            return true;
        }

        $value = $custom->valueText();
        if (! $custom->isBound()) {
            return $value === null || ! in_array(trim($value), ['', '0'], true);
        }

        return ! in_array(
            strtolower(trim((string) $value)),
            ['false', '0', 'null', '[]', 'array()'],
            true,
        );
    }

    /** @param array<string, list<ElementNode>> $chromeByEdge */
    private function conflictsWithChrome(ElementNode $safeAreaElement, string $token, array $chromeByEdge): bool
    {
        $edges = $token === 'safe-area'
            ? ['top', 'bottom']
            : ($token === 'safe-area-top' ? ['top'] : ['bottom']);

        foreach ($edges as $edge) {
            foreach ($chromeByEdge[$edge] ?? [] as $chrome) {
                if (! $this->nodesAreMutuallyExclusive($safeAreaElement, $chrome)) {
                    return true;
                }
            }
        }

        return false;
    }
}
