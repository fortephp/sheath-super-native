<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Support;

use Forte\Sheath\NativePhp\Support\Styling\StaticClassList;
use Forte\Sheath\NativePhp\Support\Theme\ThemeClass;
use Forte\Sheath\NativePhp\Support\Theme\ThemeTokenSource;
use Native\Mobile\Edge\ComponentRegistry;
use Native\Mobile\Edge\ElementRegistry;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

final class RuntimeContext
{
    /** @return array<string, mixed> */
    public static function fingerprint(): array
    {
        $context = [
            'elements' => self::elements(),
            'components' => self::components(),
            'capturedAttributes' => ElementAttributeCatalog::capturedAttributes(),
            'theme' => [
                'configuration' => ThemeTokenSource::configurationFingerprint(),
                'resolver' => TailwindOracle::runtimeThemeResolverFingerprint(),
            ],
        ];

        ElementCatalog::reset();
        ElementEventCatalog::reset();
        ReflectedElementAttributes::reset();
        PrecompilerOracle::reset();
        ComponentViewMap::reset();
        TailwindOracle::reset();
        StaticClassList::reset();
        ThemeClass::reset();

        return $context;
    }

    /** @return array<string, array{class: string, source: array<string, string>}> */
    private static function elements(): array
    {
        if (! class_exists(ElementRegistry::class)) {
            return [];
        }

        try {
            $elements = [];
            $fileHashes = [];

            foreach (ElementRegistry::all() as $tag => $class) {
                if (! is_string($class)) {
                    continue;
                }

                $elements[(string) $tag] = [
                    'class' => $class,
                    'source' => self::classSourceHashes($class, $fileHashes),
                ];
            }

            ksort($elements);

            return $elements;
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, string> */
    private static function components(): array
    {
        if (! class_exists(ComponentRegistry::class)) {
            return [];
        }

        try {
            $components = [];
            foreach (ComponentRegistry::all() as $tag => $class) {
                $components[(string) $tag] = (string) $class;
            }
            ksort($components);

            return $components;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, string|false>  $fileHashes
     * @return array<string, string>
     */
    private static function classSourceHashes(string $class, array &$fileHashes): array
    {
        if (! class_exists($class)) {
            return [];
        }

        try {
            $files = [];
            $reflection = new ReflectionClass($class);

            do {
                self::addFileHash($files, $fileHashes, $reflection->getFileName());

                if ($reflection->hasMethod('applyAttributes')) {
                    /** @var ReflectionMethod $method */
                    $method = $reflection->getMethod('applyAttributes');
                    self::addFileHash($files, $fileHashes, $method->getFileName());
                }

                foreach ($reflection->getTraits() as $trait) {
                    self::addFileHash($files, $fileHashes, $trait->getFileName());
                }

                $reflection = $reflection->getParentClass();
            } while ($reflection !== false);

            ksort($files);

            return $files;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, string>  $files
     * @param  array<string, string|false>  $fileHashes
     */
    private static function addFileHash(array &$files, array &$fileHashes, string|false $file): void
    {
        if ($file === false || ! is_file($file)) {
            return;
        }

        $hash = $fileHashes[$file] ??= hash_file('xxh128', $file);
        if ($hash !== false) {
            $files[$file] = $hash;
        }
    }
}
