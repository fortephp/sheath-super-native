<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Support;

use Closure;
use Native\Mobile\Edge\TailwindParser;
use Native\Mobile\Platform;
use ReflectionClass;
use ReflectionFunction;
use Throwable;

final class TailwindOracle
{
    private static ?string $runtimeNonce = null;

    /** @var array<string, array<string, mixed>|null> */
    private static array $parseCache = [];

    /** @var array<string, bool|null> */
    private static array $runtimeContributionCache = [];

    /** @var list<string> */
    private const array PARSER_STATE = [
        'cache',
        'unsupportedCache',
        'diagnosticScopes',
        'reportedUnsupportedByView',
        'themeResolver',
        'themeDarkResolver',
    ];

    /** @var list<string> */
    private const array PLATFORM_STATE = [
        'platform',
        'detected',
        'lastFailedAttempt',
    ];

    public static function available(): bool
    {
        return class_exists(TailwindParser::class);
    }

    public static function classDoesNothing(string $class): bool
    {
        if (! self::available()) {
            return false;
        }

        if ($class === 'glass' || str_starts_with($class, 'glass:')) {
            return false;
        }

        return self::contributesNothingOn('ios', $class)
            && self::contributesNothingOn('android', $class);
    }

    private static function contributesNothingOn(string $platform, string $class): bool
    {
        $parsed = self::parseOn($platform, $class);

        if ($parsed === null) {
            return false;
        }

        return $parsed === [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function parseOn(string $platform, string $class): ?array
    {
        if (! self::available()) {
            return null;
        }

        $key = $platform.'|'.$class;

        if (array_key_exists($key, self::$parseCache)) {
            return self::$parseCache[$key];
        }

        try {
            /** @var array<string, mixed> $parsed */
            $parsed = self::withIsolatedCompilerState(function () use ($platform, $class): array {
                self::boot();

                if (self::hasRuntimeMethod(TailwindParser::class, 'setPlatform')) {
                    TailwindParser::setPlatform($platform);
                }

                return TailwindParser::parse($class);
            });

            return self::$parseCache[$key] = $parsed;
        } catch (Throwable) {
            return self::$parseCache[$key] = null;
        }
    }

    public static function hasRuntimeThemeResolver(): ?bool
    {
        $state = self::snapshotStaticProperties(TailwindParser::class, ['themeResolver']);

        return array_key_exists('themeResolver', $state)
            ? $state['themeResolver'] !== null
            : null;
    }

    /** @return array<string, mixed>|string */
    public static function runtimeThemeResolverFingerprint(): array|string
    {
        if (! self::available()) {
            return 'unavailable';
        }

        $state = self::snapshotStaticProperties(
            TailwindParser::class,
            ['themeResolver', 'themeDarkResolver'],
        );

        return [
            'theme' => self::callableFingerprint($state['themeResolver'] ?? null),
            'dark' => self::callableFingerprint($state['themeDarkResolver'] ?? null),
        ];
    }

    public static function runtimeClassContributes(string $class): ?bool
    {
        if (! self::available() || self::hasRuntimeThemeResolver() !== true) {
            return null;
        }

        if (array_key_exists($class, self::$runtimeContributionCache)) {
            return self::$runtimeContributionCache[$class];
        }

        try {
            return self::$runtimeContributionCache[$class] = self::withIsolatedCompilerState(function () use ($class): bool {
                foreach (['ios', 'android'] as $platform) {
                    if (self::hasRuntimeMethod(TailwindParser::class, 'setPlatform')) {
                        TailwindParser::setPlatform($platform);
                    }

                    if (TailwindParser::parse($class) !== []) {
                        return true;
                    }
                }

                return false;
            });
        } catch (Throwable) {
            return self::$runtimeContributionCache[$class] = null;
        }
    }

    public static function reset(): void
    {
        self::$parseCache = [];
        self::$runtimeContributionCache = [];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private static function withIsolatedCompilerState(callable $callback): mixed
    {
        $parserState = self::snapshotStaticProperties(TailwindParser::class, self::PARSER_STATE);
        $platformState = self::snapshotStaticProperties(Platform::class, self::PLATFORM_STATE);

        try {
            return $callback();
        } finally {
            self::restoreStaticProperties(TailwindParser::class, $parserState);
            self::restoreStaticProperties(Platform::class, $platformState);
        }
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $properties
     * @return array<string, mixed>
     */
    private static function snapshotStaticProperties(string $class, array $properties): array
    {
        $state = [];

        try {
            $reflection = new ReflectionClass($class);

            foreach ($properties as $property) {
                if ($reflection->hasProperty($property)) {
                    $state[$property] = $reflection->getProperty($property)->getValue();
                }
            }
        } catch (Throwable) {
        }

        return $state;
    }

    /**
     * @param  class-string  $class
     * @param  array<string, mixed>  $state
     */
    private static function restoreStaticProperties(string $class, array $state): void
    {
        if (! class_exists($class)) {
            return;
        }

        $reflection = new ReflectionClass($class);

        foreach ($state as $property => $value) {
            try {
                $reflection->getProperty($property)->setValue(null, $value);
            } catch (Throwable) {
            }
        }
    }

    private static function boot(): void
    {
        try {
            TailwindParser::setThemeResolver(static fn (string $token): string => '#000000');
            TailwindParser::setThemeDarkResolver(static fn (string $token): string => '#FFFFFF');
        } catch (Throwable) {
        }
    }

    private static function hasRuntimeMethod(string $class, string $method): bool
    {
        return method_exists($class, $method);
    }

    private static function callableFingerprint(mixed $callback): mixed
    {
        if ($callback === null) {
            return null;
        }

        if ($callback instanceof Closure) {
            $reflection = new ReflectionFunction($callback);

            return [
                'type' => 'closure',
                'file' => $reflection->getFileName() ?: null,
                'startLine' => $reflection->getStartLine(),
                'endLine' => $reflection->getEndLine(),
                'captures' => self::fingerprintValue($reflection->getStaticVariables()),
                'boundObject' => self::fingerprintValue($reflection->getClosureThis()),
                'id' => spl_object_id($callback),
                'runtime' => self::$runtimeNonce ??= bin2hex(random_bytes(16)),
            ];
        }

        if (is_array($callback)) {
            return self::fingerprintValue($callback);
        }

        if (is_string($callback)) {
            return ['type' => 'string', 'value' => $callback];
        }

        if (is_object($callback)) {
            return [
                'type' => 'object',
                'class' => $callback::class,
                'id' => spl_object_id($callback),
                'runtime' => self::$runtimeNonce ??= bin2hex(random_bytes(16)),
            ];
        }

        return ['type' => get_debug_type($callback)];
    }

    private static function fingerprintValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 12) {
            return self::opaqueValueFingerprint('depth-limit');
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[(string) $key] = self::fingerprintValue($item, $depth + 1);
            }

            return $result;
        }

        if (is_string($value)) {
            return ['type' => 'string', 'hash' => hash('xxh128', $value)];
        }

        if (is_object($value)) {
            return self::opaqueValueFingerprint('object', [
                'class' => $value::class,
                'id' => spl_object_id($value),
            ]);
        }

        if (is_resource($value)) {
            return self::opaqueValueFingerprint('resource', [
                'resourceType' => get_resource_type($value),
            ]);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private static function opaqueValueFingerprint(string $type, array $metadata = []): array
    {
        return [
            'type' => $type,
            ...$metadata,
            // Opaque mutable state cannot be hashed safely. A context nonce
            // disables cross-command cache reuse while preserving sharing
            // between NativePHP rules inside the current lint command.
            'context' => bin2hex(random_bytes(16)),
        ];
    }
}
