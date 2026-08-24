<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Support;

use Forte\Extensions\ForteExtension;
use Forte\Lexer\Extension\LexerContext;
use Forte\Lexer\Extension\LexerExtension;
use Forte\Lexer\State;
use Forte\Lexer\Tokens\TokenType;
use Forte\Lexer\Tokens\TokenTypeRegistry;
use Forte\Parser\ParserOptions;

/** Parse the whitespace-tolerant tag prefix accepted by NativePHP without rewriting the source. */
final class SpacedNativeTagExtension implements ForteExtension, LexerExtension
{
    public const string ID = 'nativephp-spaced-native-tags';

    public static function configure(ParserOptions $options): void
    {
        if (self::isConfigured($options)) {
            return;
        }

        $options->extension(new self);
    }

    public static function isConfigured(ParserOptions $options): bool
    {
        return $options->hasExtensions()
            && $options->getExtensionRegistry()->has(self::ID);
    }

    public function id(): string
    {
        return self::ID;
    }

    public function name(): string
    {
        return 'NativePHP spaced tag syntax';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    /** @return list<string> */
    public function dependencies(): array
    {
        return [];
    }

    /** @return list<string> */
    public function conflicts(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function getOptions(): array
    {
        return [];
    }

    public function priority(): int
    {
        return 100;
    }

    public function triggerCharacters(): string
    {
        return '<';
    }

    /** @return list<int> */
    public function registerTokenTypes(TokenTypeRegistry $registry): array
    {
        return [];
    }

    public function shouldActivate(LexerContext $ctx): bool
    {
        $match = $this->match($ctx);

        return $match !== null && $match['spaced'];
    }

    public function tokenize(LexerContext $ctx): bool
    {
        $match = $this->match($ctx);
        if ($match === null || ! $match['spaced']) {
            return false;
        }

        $start = $ctx->position();
        $ctx->emit(TokenType::LessThan, $start, $start + 1);

        if ($match['slashOffset'] !== null) {
            $slash = $start + $match['slashOffset'];
            $ctx->emit(TokenType::Slash, $slash, $slash + 1);
        }

        // Only the logical element name becomes the TagName token. Gaps in
        // token offsets retain every authored prefix byte while making opening
        // and closing tags pair regardless of their whitespace spelling.
        $nameStart = $start + $match['nameOffset'];
        $ctx->emit(TokenType::TagName, $nameStart, $nameStart + strlen($match['name']));
        $ctx->beginElementTag('native:'.$match['name'], $match['slashOffset'] !== null);
        $ctx->setPosition($start + $match['length']);
        $ctx->setState(State::BeforeAttrName);

        return true;
    }

    /**
     * @return array{length: int, name: string, nameOffset: int, slashOffset: int|null, spaced: bool}|null
     */
    private function match(LexerContext $ctx): ?array
    {
        $matched = preg_match(
            '/\G<(\/?)\s*native\s*:\s*([A-Za-z0-9_-]+)/A',
            $ctx->source(),
            $matches,
            PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
            $ctx->position(),
        );
        if ($matched !== 1) {
            return null;
        }

        $full = $matches[0][0];
        $slash = $matches[1][0] ?? '';
        $name = $matches[2][0];
        if (! is_string($full) || ! is_string($name)) {
            return null;
        }

        return [
            'length' => strlen($full),
            'name' => $name,
            'nameOffset' => $matches[2][1] - $ctx->position(),
            'slashOffset' => $slash === '' ? null : $matches[1][1] - $ctx->position(),
            'spaced' => $full !== '<'.$slash.'native:'.$name,
        ];
    }
}
