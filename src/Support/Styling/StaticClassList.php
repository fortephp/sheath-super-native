<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Support\Styling;

use Forte\Ast\Elements\Attribute;
use WeakMap;

final class StaticClassList
{
    /** @var WeakMap<Attribute, list<string>>|null */
    private static ?WeakMap $cache = null;

    /** @return list<string> */
    public static function tokens(Attribute $attribute): array
    {
        self::$cache ??= new WeakMap;

        if (isset(self::$cache[$attribute])) {
            return self::$cache[$attribute];
        }

        if ($attribute->isBound() || ! $attribute->isStatic()) {
            return self::$cache[$attribute] = [];
        }

        $value = $attribute->valueText();
        if ($value === null || $value === '') {
            return self::$cache[$attribute] = [];
        }

        $withoutExpressions = (string) preg_replace(
            '/\{\{.*?\}\}|\{!!.*?!!\}|@\w+\s*\([^)]*\)/s',
            "\u{0}",
            $value,
        );

        $tokens = [];
        foreach (preg_split('/\s+/', trim($withoutExpressions, " \t\n\r\x0B")) ?: [] as $token) {
            if ($token === '' || preg_match('/[{}$\'"\x00]/', $token)) {
                continue;
            }

            $tokens[] = $token;
        }

        return self::$cache[$attribute] = $tokens;
    }

    public static function reset(): void
    {
        self::$cache = null;
    }
}
