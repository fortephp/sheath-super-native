<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Support;

use Forte\Ast\Document\Document;
use Forte\Parser\ParserOptions;

/** @internal */
final class NativeSyntaxDocument
{
    public static function from(Document $document, ParserOptions $parserOptions): Document
    {
        $source = $document->source();
        if (preg_match(
            '/<\/?(?:\s+native\s*:\s*|native\s+:\s*|native\s*:\s+)[A-Za-z0-9_-]+/',
            $source,
        ) !== 1
            || SpacedNativeTagExtension::isConfigured($parserOptions)) {
            return $document;
        }

        $nativeOptions = ParserOptions::make()
            ->directives($parserOptions->getDirectives())
            ->components($parserOptions->getComponentManager())
            ->merge($parserOptions);
        SpacedNativeTagExtension::configure($nativeOptions);

        return Document::parse($source, $nativeOptions)
            ->setFilePath($document->getFilePath());
    }
}
