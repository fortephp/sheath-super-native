<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Support;

use ReflectionClass;
use ReflectionMethod;
use Throwable;

final class ReflectedElementAttributes
{
    /** @var array<class-string, list<string>|null> */
    private static array $cache = [];

    /** @return list<string>|null */
    public static function forTag(string $tag): ?array
    {
        $class = ElementCatalog::elementClass($tag);
        if ($class === null) {
            return null;
        }

        return self::$cache[$class] ??= self::forClass($class);
    }

    /**
     * @param  class-string  $class
     * @return list<string>|null
     */
    private static function forClass(string $class): ?array
    {
        try {
            $reflection = new ReflectionClass($class);
            $attributes = [];
            $seenMethods = [];

            do {
                if ($reflection->hasMethod('applyAttributes')) {
                    $method = $reflection->getMethod('applyAttributes');
                    $methodKey = $method->getDeclaringClass()->getName().'::'.$method->getName();

                    if (! isset($seenMethods[$methodKey])) {
                        $seenMethods[$methodKey] = true;
                        $keys = self::literalAttributeKeys($method);
                        if ($keys === null) {
                            return null;
                        }

                        foreach ($keys as $attribute) {
                            $attributes[$attribute] = true;
                        }
                    }
                }

                $reflection = $reflection->getParentClass();
            } while ($reflection !== false);

            return array_keys($attributes);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string>|null */
    private static function literalAttributeKeys(ReflectionMethod $method): ?array
    {
        $file = $method->getFileName();
        if ($file === false || ! is_file($file)) {
            return [];
        }

        $lines = file($file);
        if ($lines === false) {
            return [];
        }

        $source = implode('', array_slice(
            $lines,
            max(0, $method->getStartLine() - 1),
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        if (preg_match('/\$attrs\s*\[\s*(?![\'\"])/', $source) === 1) {
            return null;
        }

        preg_match_all('/\$attrs\s*\[\s*([\'\"])([^\'\"]+)\1\s*\]/', $source, $matches);

        return array_values(array_unique($matches[2]));
    }

    public static function reset(): void
    {
        self::$cache = [];
    }
}
