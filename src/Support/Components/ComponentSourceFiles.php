<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Support\Components;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * @phpstan-type SourceFile array{path: string, source: string}
 * @phpstan-type SourceFingerprint array<string, string|array<string, string>>
 * @phpstan-type SourceSnapshot array{files: list<SourceFile>, fingerprint: SourceFingerprint}
 */
final class ComponentSourceFiles
{
    /** @var SourceSnapshot|null */
    private static ?array $snapshot = null;

    private static ?string $cacheKey = null;

    /**
     * @param  list<string>  $paths
     * @return SourceSnapshot
     */
    public static function get(array $paths): array
    {
        $key = serialize($paths);

        if (self::$snapshot !== null && self::$cacheKey === $key) {
            return self::$snapshot;
        }

        self::$cacheKey = $key;

        return self::$snapshot = self::scan($paths);
    }

    /**
     * Refreshes the inventory while retaining it for the following analysis pass.
     *
     * @param  list<string>  $paths
     * @return SourceSnapshot
     */
    public static function refresh(array $paths): array
    {
        self::$cacheKey = serialize($paths);

        return self::$snapshot = self::scan($paths);
    }

    public static function reset(): void
    {
        self::$snapshot = null;
        self::$cacheKey = null;
    }

    /**
     * @param  list<string>  $paths
     * @return SourceSnapshot
     */
    private static function scan(array $paths): array
    {
        $files = [];
        $fingerprint = [];

        foreach ($paths as $path) {
            $normalizedPath = str_replace('\\', '/', $path);

            if (! is_dir($path)) {
                $fingerprint[$normalizedPath] = 'missing';

                continue;
            }

            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
                );
            } catch (Throwable) {
                $fingerprint[$normalizedPath] = 'unreadable';

                continue;
            }

            $pathFingerprint = [];

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $filePath = $file->getPathname();
                $relative = ltrim(str_replace('\\', '/', substr($filePath, strlen($path))), '/');
                $source = @file_get_contents($filePath);

                if (! is_string($source)) {
                    $pathFingerprint[$relative] = 'unreadable';

                    continue;
                }

                $pathFingerprint[$relative] = hash('xxh128', $source);
                $files[] = ['path' => $filePath, 'source' => $source];
            }

            ksort($pathFingerprint);
            $fingerprint[$normalizedPath] = $pathFingerprint;
        }

        ksort($fingerprint);

        return ['files' => $files, 'fingerprint' => $fingerprint];
    }
}
