<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules\Elements;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\NativePhp\Rules\BaseRule;
use Forte\Sheath\NativePhp\Support\ElementCatalog;
use Forte\Sheath\NativePhp\Support\Suggestions;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleContext;

#[RequiresPackage('nativephp/mobile')]
class UnknownElementRule extends BaseRule
{
    public function getId(): string
    {
        return 'native-unknown-element';
    }

    public function getDescription(): string
    {
        return 'Reports unknown, incomplete, or unbalanced native tags that fail when the screen renders.';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
    }

    public function check(Document $document, RuleContext $context): void
    {
        if (! $this->targetsSupportedCompiler($context)) {
            return;
        }

        $covered = [];
        $document->getElements()->each(function (ElementNode $element) use ($context, &$covered): void {
            if ($this->nativePrefixedTagName($element) !== null) {
                $covered[$element->startOffset()] = true;
            }
            $this->checkElement($element, $context);
        });

        $this->checkCompilerRecognizedPrefixedTags($document, $context, $covered);
        $this->checkTruncatedNativeTag($document, $context, $covered);
        $this->checkCompilerTagBalance($document, $context);
    }

    private function createTagRenameFix(RuleContext $context, ElementNode $element, string $name, string $closest): ?Fix
    {
        if (! $element->isSelfClosing() || $element->startOffset() < 0) {
            return null;
        }

        $from = $element->startOffset() + strlen('<native:');
        $to = $from + strlen($name);

        if ($context->getSourceAt($from, $to) !== $name) {
            return null;
        }

        return new Fix($from, $to, $closest);
    }

    private function checkElement(ElementNode $element, RuleContext $context): void
    {
        $name = $this->nativePrefixedTagName($element);
        if ($name === null) {
            return;
        }

        if ($this->isBlockedByComponentSlot($element)) {
            return;
        }

        if ($name !== strtolower($name) || ! ElementCatalog::isKnownTag($name)) {
            $knownTags = ElementCatalog::knownTags();
            $closest = Suggestions::closest(strtolower($name), $knownTags);

            $context->report(
                $element,
                $this->unknownElementMessage($name)
                    .Suggestions::hintFor($closest),
                $closest === null ? null : $this->createTagRenameFix($context, $element, $name, $closest)
            );
        }
    }

    /** @param array<int, true> $covered */
    private function checkCompilerRecognizedPrefixedTags(Document $document, RuleContext $context, array $covered): void
    {
        $attrs = "((?:[^>\"'\/]*+(?:\"[^\"]*+\"|'[^']*+')[^>\"'\/]*+)*+|[^>\"'\/]*+)";
        $pattern = '/<\s*native\s*:\s*([a-zA-Z0-9\-_]+)\s*'.$attrs.'\s*\/?>/s';
        preg_match_all(
            $pattern,
            $this->compilerVisibleSource($document->source()),
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        foreach ($matches as $match) {
            $source = $match[0][0];
            $start = $match[0][1];
            $name = $match[1][0];

            if (isset($covered[$start])
                || ($name === strtolower($name) && ElementCatalog::isKnownTag($name))) {
                continue;
            }

            $context->reportAt(
                Position::fromOffset($document, $start),
                Position::fromOffset($document, $start + strlen($source)),
                $this->unknownElementMessage($name)
                    .Suggestions::hint(strtolower($name), ElementCatalog::knownTags()),
            );
        }
    }

    private function unknownElementMessage(string $name): string
    {
        $status = ElementCatalog::isReflected() ? 'not registered' : 'unknown';

        return "<native:{$name}> is {$status} and will fail at render time.";
    }

    /** @param array<int, true> $covered */
    private function checkTruncatedNativeTag(Document $document, RuleContext $context, array $covered): void
    {
        $source = $this->compilerVisibleSource($document->source());
        $matched = preg_match('/<\/?\s*native\s*:\s*[^<>]*\z/s', $source, $match, PREG_OFFSET_CAPTURE);
        if ($matched !== 1 && $this->isNativeView($document, $context->getFilePath())) {
            $short = $this->shortTagAlternation();
            $matched = $short === null
                ? 0
                : preg_match('/<\/?\s*'.$short.'(?:\s+[^<>]*)?\z/s', $source, $match, PREG_OFFSET_CAPTURE);
        }
        if ($matched !== 1) {
            return;
        }

        $source = $match[0][0];
        $start = $match[0][1];
        if (isset($covered[$start])
            && preg_match('/native\s*:\s*[A-Za-z0-9_-]+/', $source) !== 1) {
            // The ordinary unknown-element diagnostic already owns a bare
            // `<native:` parser node.
            return;
        }

        $context->reportAt(
            Position::fromOffset($document, $start),
            Position::fromOffset($document, $start + strlen($source)),
            'Native tag is incomplete; add `>` or `/>` so NativePHP can build the element tree.',
        );
    }

    private function checkCompilerTagBalance(Document $document, RuleContext $context): void
    {
        $source = $this->compilerVisibleSource($document->source());
        $attrs = "((?:[^>\"'\/]*+(?:\"[^\"]*+\"|'[^']*+')[^>\"'\/]*+)*+|[^>\"'\/]*+)";
        $events = [];

        $this->collectTagEvents(
            $source,
            '/<(?<close>\/)?\s*native\s*:\s*[a-zA-Z0-9\-_]+\s*'.$attrs.'\s*(?<self>\/)?\s*>/s',
            $events,
        );

        $short = $this->isNativeView($document, $context->getFilePath())
            ? $this->shortTagAlternation()
            : null;
        if ($short !== null) {
            $this->collectTagEvents(
                $source,
                '/<(?<close>\/)?'.$short.'\s*'.$attrs.'\s*(?<self>\/)?\s*>/s',
                $events,
            );
        }

        usort($events, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);
        $stack = [];
        foreach ($events as $event) {
            if ($event['kind'] === 'leaf') {
                continue;
            }

            if ($event['kind'] === 'open') {
                $stack[] = $event;

                continue;
            }

            if ($stack !== []) {
                array_pop($stack);

                continue;
            }

            $context->reportAt(
                Position::fromOffset($document, $event['start']),
                Position::fromOffset($document, $event['end']),
                'Native closing tag has no open element; NativePHP will close an empty collector stack.',
            );
        }

        $truncatedClosing = preg_match('/<\/\s*native\s*:\s*[^<>]*\z/s', $source) === 1
            || ($short !== null && preg_match('/<\/\s*'.$short.'(?:\s+[^<>]*)?\z/s', $source) === 1);
        if ($truncatedClosing && $stack !== []) {
            // The incomplete close owns the diagnostic. If its missing `>`
            // were restored, the compiler would pop the latest opening.
            array_pop($stack);
        }

        foreach ($stack as $event) {
            $context->reportAt(
                Position::fromOffset($document, $event['start']),
                Position::fromOffset($document, $event['end']),
                'Native element is not self-closing and has no compiler closing tag.',
            );
        }
    }

    /**
     * @param  list<array{kind: 'leaf'|'open'|'close', start: int, end: int}>  $events
     */
    private function collectTagEvents(string $source, string $pattern, array &$events): void
    {
        $matched = preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if (! is_int($matched) || $matched === 0) {
            return;
        }

        foreach ($matches as $match) {
            $tag = $match[0][0];
            $start = $match[0][1];
            $events[] = [
                'kind' => ($match['close'][0] ?? '') === '/'
                    ? 'close'
                    : (($match['self'][0] ?? '') === '/' ? 'leaf' : 'open'),
                'start' => $start,
                'end' => $start + strlen($tag),
            ];
        }
    }

    private function shortTagAlternation(): ?string
    {
        $shortTags = ElementCatalog::shortFormTags();
        if ($shortTags === []) {
            return null;
        }

        usort($shortTags, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return '(?:'.implode('|', array_map(
            static fn (string $tag): string => preg_quote($tag, '/'),
            $shortTags,
        )).')';
    }

    /**
     * Mask blocks Laravel removes or placeholders before invoking Blade
     * precompilers. Replacing bytes with spaces preserves every source offset.
     */
    private function compilerVisibleSource(string $source): string
    {
        $visible = preg_replace_callback(
            '/\{\{--.*?--\}\}|(?<!@)@verbatim\b.*?@endverbatim|(?<!@)@php\b.*?@endphp/s',
            static fn (array $match): string => str_repeat(' ', strlen($match[0])),
            $source,
        );

        return is_string($visible) ? $visible : $source;
    }
}
