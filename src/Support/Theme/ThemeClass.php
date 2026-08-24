<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Support\Theme;

final class ThemeClass
{
    private const string THEME_TOKEN_PATTERN = '/^(?:(?:ios|android|dark):)*(?:bg|text|border|from|via|to)-theme-([^\/\s]+?)(?:\/\S+)?$/';

    private const string FIXED_COLOR_PATTERN = '/^(?:(?:ios|android|dark):)*(?:bg|text|border)-\[#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})\](?:\/\d+)?$/';

    /** @var array<string, self|null> */
    private static array $cache = [];

    private function __construct(
        public readonly string $value,
        public readonly ?string $token,
        public readonly bool $fixedColor,
    ) {}

    public static function parse(string $class): ?self
    {
        if (array_key_exists($class, self::$cache)) {
            return self::$cache[$class];
        }

        if (preg_match(self::THEME_TOKEN_PATTERN, $class, $match) === 1) {
            return self::$cache[$class] = new self($class, $match[1], false);
        }

        if (preg_match(self::FIXED_COLOR_PATTERN, $class) === 1) {
            return self::$cache[$class] = new self($class, null, true);
        }

        return self::$cache[$class] = null;
    }

    public static function reset(): void
    {
        self::$cache = [];
    }
}
