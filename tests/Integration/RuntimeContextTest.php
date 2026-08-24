<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Tests\Integration;

use Forte\Sheath\NativePhp\Rules\Elements\UnknownElementRule;
use Forte\Sheath\NativePhp\Tests\Fixtures\CacheContextElementFixture;
use Illuminate\Support\Facades\Artisan;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\NativeElementCollector;

/** @return array{root: string, view: string, cache: string} */
function nativeCacheSandbox(string $source): array
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sheath-native-context-'.uniqid();
    $views = $root.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'native';
    mkdir($views, 0777, true);
    $view = $views.DIRECTORY_SEPARATOR.'probe.blade.php';
    file_put_contents($view, $source);

    return ['root' => $root, 'view' => $view, 'cache' => $root.DIRECTORY_SEPARATOR.'cache.json'];
}

/** @param array{root: string, view: string, cache: string} $sandbox */
function removeNativeCacheSandbox(array $sandbox): void
{
    foreach ([$sandbox['view'], $sandbox['cache']] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    $native = dirname($sandbox['view']);
    $views = dirname($native);
    if (is_dir($native)) {
        rmdir($native);
    }
    if (is_dir($views)) {
        rmdir($views);
    }
    if (is_dir($sandbox['root'])) {
        rmdir($sandbox['root']);
    }
}

it('misses a prior lint cache entry when the element registry changes', function (): void {
    $sandbox = nativeCacheSandbox('<column><native:cache-probe /></column>');

    try {
        $arguments = [
            'paths' => [$sandbox['view']],
            '--preset' => ['empty'],
            '--rule' => ['native-unknown-element:error'],
            '--cache' => true,
            '--cache-location' => $sandbox['cache'],
            '--stats' => true,
        ];

        $status = Artisan::call('sheath:lint', $arguments);

        expect($status)->not->toBe(0);

        ElementRegistry::register('cache_probe', CacheContextElementFixture::class);

        $status = Artisan::call('sheath:lint', $arguments);

        expect($status)->toBe(0);
    } finally {
        ElementRegistry::reset();
        removeNativeCacheSandbox($sandbox);
    }
});

it('misses a prior lint cache entry when the native theme changes', function (): void {
    $sandbox = nativeCacheSandbox('<column class="bg-theme-future"><text>x</text></column>');

    try {
        $arguments = [
            'paths' => [$sandbox['view']],
            '--preset' => ['empty'],
            '--rule' => ['native-unknown-theme-token:error'],
            '--cache' => true,
            '--cache-location' => $sandbox['cache'],
            '--stats' => true,
        ];

        config()->set('native-ui.theme', ['light' => ['present' => '#000000']]);
        $status = Artisan::call('sheath:lint', $arguments);

        expect($status)->not->toBe(0);

        config()->set('native-ui.theme', ['light' => ['future' => '#FFFFFF']]);
        $status = Artisan::call('sheath:lint', $arguments);

        expect($status)->toBe(0);
    } finally {
        removeNativeCacheSandbox($sandbox);
    }
});

it('fingerprints registered element source files without instantiating them', function (): void {
    $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'SheathCacheElement'.uniqid().'.php';
    $class = 'SheathCacheElement'.str_replace('.', '', uniqid('', true));

    try {
        file_put_contents($file, "<?php\nclass {$class} extends \\Native\\Mobile\\Edge\\Elements\\Column {}\n");
        require_once $file;
        ElementRegistry::register('source_probe', $class);

        $before = (new UnknownElementRule)->cacheContext([]);
        file_put_contents($file, "// source changed\n", FILE_APPEND);
        clearstatcache(true, $file);
        $after = (new UnknownElementRule)->cacheContext([]);

        expect($after)->not->toBe($before);
    } finally {
        ElementRegistry::reset();
        if (is_file($file)) {
            unlink($file);
        }
    }
});

it('fingerprints collector-captured attribute mappings', function (): void {
    try {
        NativeElementCollector::captureAttribute('track', 'first_prop');
        $before = (new UnknownElementRule)->cacheContext([]);

        NativeElementCollector::captureAttribute('track', 'second_prop');
        $after = (new UnknownElementRule)->cacheContext([]);

        expect($after)->not->toBe($before);
    } finally {
        NativeElementCollector::stopCapturingAttributes();
    }
});

it('fingerprints collector-captured attribute registration order', function (): void {
    try {
        NativeElementCollector::captureAttribute('alpha', 'shared_prop');
        NativeElementCollector::captureAttribute('zeta', 'shared_prop');
        $forward = (new UnknownElementRule)->cacheContext([]);

        NativeElementCollector::stopCapturingAttributes();
        NativeElementCollector::captureAttribute('zeta', 'shared_prop');
        NativeElementCollector::captureAttribute('alpha', 'shared_prop');
        $reverse = (new UnknownElementRule)->cacheContext([]);
        expect($reverse)->not->toBe($forward);
    } finally {
        NativeElementCollector::stopCapturingAttributes();
    }
});
