<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Support;

use Forte\Sheath\NativePhp\Support\Components\ComponentSourceFiles;
use Native\Mobile\Attributes\Locked;
use Native\Mobile\Edge\NativeComponent;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * @phpstan-type ComponentMetadata array{
 *     file: string,
 *     public: array<string, bool>,
 *     nonPublic: array<string, bool>,
 *     properties: array<string, bool>,
 *     nonPublicProperties: array<string, bool>,
 *     locked: array<string, bool>,
 *     readonly: array<string, bool>,
 *     signatures: array<string, int>,
 *     reflected: bool
 * }
 * @phpstan-type ComponentOwners array<string, ComponentMetadata>
 * @phpstan-type ViewOwners array<string, ComponentOwners>
 * @phpstan-type SourceToken array{int, string, int}|string
 */
final class ComponentViewMap
{
    /** @var ViewOwners|null */
    private static ?array $cache = null;

    private static ?string $cacheKey = null;

    /**
     * @param  array<string>  $componentPaths
     * @return ComponentOwners
     */
    private static function componentsForView(string $viewName, array $componentPaths): array
    {
        $map = self::scan($componentPaths);
        $owners = $map[$viewName] ?? [];

        if (str_starts_with($viewName, 'native.')) {
            $short = substr($viewName, strlen('native.'));

            if ($short !== '') {
                $owners += $map[$short] ?? [];
            }
        }

        return $owners;
    }

    /**
     * @param  array<string>  $componentPaths
     * @return ComponentMetadata|null
     */
    public static function soleComponentForPath(string $filePath, array $componentPaths): ?array
    {
        $viewName = self::viewNameForPath($filePath);
        if ($viewName === null) {
            return null;
        }

        $owners = self::componentsForView($viewName, $componentPaths);

        return count($owners) === 1 ? reset($owners) : null;
    }

    private static function viewNameForPath(string $filePath): ?string
    {
        $normalized = str_replace('\\', '/', $filePath);

        if (preg_match('#(?:^|/)views/(.+)\.blade\.php$#', $normalized, $m) !== 1) {
            return null;
        }

        return str_replace('/', '.', $m[1]);
    }

    /**
     * @param  array<string>  $componentPaths
     * @return array<string, string|array<string, string>>
     */
    public static function sourceFingerprint(array $componentPaths): array
    {
        self::reset();

        return ComponentSourceFiles::refresh(array_values($componentPaths))['fingerprint'];
    }

    /**
     * @param  array<string>  $componentPaths
     * @return ViewOwners
     */
    private static function scan(array $componentPaths): array
    {
        $key = serialize($componentPaths);

        if (self::$cache !== null && self::$cacheKey === $key) {
            return self::$cache;
        }

        self::$cacheKey = $key;
        $map = [];
        $inherited = self::basePublicMethods();

        foreach (ComponentSourceFiles::get(array_values($componentPaths))['files'] as $file) {
            $source = $file['source'];

            if ($source === '') {
                continue;
            }

            $views = self::renderViewNames($source);
            if ($views === []) {
                continue;
            }

            $entry = self::analyzeComponent($file['path'], $source, $inherited);

            if ($entry === null) {
                continue;
            }

            foreach ($views as $viewName) {
                $map[str_replace('/', '.', $viewName)][$file['path']] = $entry;
            }
        }

        return self::$cache = $map;
    }

    /**
     * @return list<string>
     */
    private static function renderViewNames(string $source): array
    {
        try {
            $tokens = token_get_all($source);
        } catch (Throwable) {
            return [];
        }

        foreach ($tokens as $index => $token) {
            if (! is_array($token)
                || $token[0] !== T_FUNCTION
                || self::functionName($tokens, $index) !== 'render') {
                continue;
            }

            $body = self::functionBody($tokens, $index);

            preg_match_all('/(?<![\w])(?:view|\$this->view)\(\s*[\'\"]([\w.\-\/]+)[\'\"]/', $body, $matches);

            return array_values(array_unique($matches[1]));
        }

        return [];
    }

    /**
     * @param  list<SourceToken>  $tokens
     */
    private static function functionName(array $tokens, int $index): ?string
    {
        $ignored = [
            T_WHITESPACE,
            T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG,
            T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG,
        ];

        for ($cursor = $index + 1, $count = count($tokens); $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];

            if (is_array($token) && in_array($token[0], $ignored, true)) {
                continue;
            }

            return is_array($token) && $token[0] === T_STRING
                ? strtolower($token[1])
                : null;
        }

        return null;
    }

    /**
     * @param  list<SourceToken>  $tokens
     */
    private static function functionBody(array $tokens, int $index): string
    {
        $body = '';
        $depth = 0;
        $started = false;

        for ($cursor = $index + 1, $count = count($tokens); $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];
            $text = is_array($token) ? $token[1] : $token;

            if ($text === '{') {
                $depth++;
                $started = true;

                continue;
            }

            if (! $started) {
                continue;
            }

            if ($text === '}' && --$depth === 0) {
                break;
            }

            $body .= $text;
        }

        return $body;
    }

    /**
     * @param  array<string, bool>  $inherited
     * @return ComponentMetadata|null
     */
    private static function analyzeComponent(string $path, string $source, array $inherited): ?array
    {
        $fqcn = self::fqcnFrom($source);

        if ($fqcn !== null && class_exists($fqcn)) {
            try {
                $reflection = new ReflectionClass($fqcn);

                if (class_exists(NativeComponent::class) && ! $reflection->isSubclassOf(NativeComponent::class)) {
                    return null;
                }

                $entry = ['file' => $path, 'public' => [], 'nonPublic' => [], 'properties' => [], 'nonPublicProperties' => [], 'locked' => [], 'readonly' => [], 'signatures' => [], 'reflected' => true];

                foreach ($reflection->getMethods() as $method) {
                    if ($method->isPrivate()) {
                        $entry['nonPublic'][$method->getName()] = true;
                    } else {
                        $entry['public'][$method->getName()] = true;
                        $entry['signatures'][$method->getName()] = $method->getNumberOfRequiredParameters();
                    }
                }

                foreach ($reflection->getProperties() as $property) {
                    if ($property->isStatic()) {
                        continue;
                    }

                    if (! $property->isPublic()) {
                        $entry['nonPublicProperties'][$property->getName()] = true;

                        continue;
                    }

                    $entry['properties'][$property->getName()] = true;

                    if ($property->getAttributes(Locked::class) !== []) {
                        $entry['locked'][$property->getName()] = true;
                    }
                    if ($property->isReadOnly()) {
                        $entry['readonly'][$property->getName()] = true;
                    }
                }

                return $entry;
            } catch (Throwable) {
            }
        }

        preg_match_all('/(?:public|protected)\s+(?:static\s+)?function\s+(\w+)/', $source, $publics);
        preg_match_all('/private\s+(?:static\s+)?function\s+(\w+)/', $source, $nonPublics);

        return [
            'file' => $path,
            'public' => array_fill_keys($publics[1], true) + $inherited,
            'nonPublic' => array_fill_keys($nonPublics[1], true),
            'properties' => [],
            'nonPublicProperties' => [],
            'locked' => [],
            'readonly' => [],
            'signatures' => [],
            'reflected' => false,
        ];
    }

    private static function fqcnFrom(string $source): ?string
    {
        if (preg_match('/^namespace\s+([\w\\\\]+);/m', $source, $ns) !== 1
            || preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $source, $class) !== 1) {
            return null;
        }

        return $ns[1].'\\'.$class[1];
    }

    /**
     * @return array<string, bool>
     */
    private static function basePublicMethods(): array
    {
        if (! class_exists(NativeComponent::class)) {
            return [];
        }

        try {
            $methods = [];

            foreach (new ReflectionClass(NativeComponent::class)->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
                $methods[$method->getName()] = true;
            }

            return $methods;
        } catch (Throwable) {
            return [];
        }
    }

    public static function reset(): void
    {
        self::$cache = null;
        self::$cacheKey = null;
        ComponentSourceFiles::reset();
    }
}
