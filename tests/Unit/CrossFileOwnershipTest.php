<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Tests\Unit;

use Forte\Sheath\NativePhp\Rules\Interaction\CallbackRule;
use Forte\Sheath\NativePhp\Rules\Interaction\ModelPropertyRule;
use Forte\Sheath\NativePhp\Support\ComponentViewMap;
use Forte\Sheath\Testing\RuleTester;

require_once __DIR__.'/../Fixtures/NativeComponents/MisleadingViewReference.php';
require_once __DIR__.'/../Fixtures/NativeComponents/FixtureDrawerHome.php';
require_once __DIR__.'/../Fixtures/NativeComponents/UserCard.php';
require_once __DIR__.'/../Fixtures/NativeComponents/VisibilityScreen.php';

beforeEach(function (): void {
    ComponentViewMap::reset();
});

it('stands down on layout-owned drawer partials', function (): void {
    $rule = new CallbackRule;
    $rule->setOptions(['componentPaths' => [__DIR__.'/../Fixtures/NativeComponents']]);

    (new RuleTester)->withFilePath('resources/views/native/fixture-menu.blade.php')->run($rule, [
        'valid' => [
            '<column><button @press="navigate(\'/settings\')">Settings</button></column>',
            '<column><button @press="closeDrawer">Close</button></column>',
        ],
        'invalid' => [],
    ]);
});

it('still fires on views owned solely by a real component', function (): void {
    $rule = new CallbackRule;
    $rule->setOptions(['componentPaths' => [__DIR__.'/../Fixtures/NativeComponents']]);

    (new RuleTester)->withFilePath('resources/views/native/fixture-home.blade.php')->run($rule, [
        'valid' => [
            '<column><button @press="openSettings">x</button></column>',
            '<column><button @press="navigate(\'/x\')">x</button></column>',
        ],
        'invalid' => [
            ['code' => '<column><button @press="brokenHandler">x</button></column>', 'errors' => 1],
        ],
    ]);
});

it('resolves short view names through the compiler finder location', function (): void {
    $rule = new CallbackRule;
    $rule->setOptions(['componentPaths' => [__DIR__.'/../Fixtures/NativeComponents']]);

    (new RuleTester)->withFilePath('resources/views/native/fixture-short.blade.php')->run($rule, [
        'valid' => [
            '<column><button @press="submitForm">x</button></column>',
        ],
        'invalid' => [
            ['code' => '<column><button @press="submitFrom">x</button></column>', 'errors' => [[]]],
        ],
    ]);
});

it('stands down when spellings split ownership across components', function (): void {
    $rule = new CallbackRule;
    $rule->setOptions(['componentPaths' => [__DIR__.'/../Fixtures/NativeComponents']]);

    (new RuleTester)->withFilePath('resources/views/native/fixture-split.blade.php')->run($rule, [
        'valid' => [
            '<column><button @press="definitelyMissing">x</button></column>',
        ],
        'invalid' => [
            ['code' => '<column><button @press="$selected = 1">x</button></column>', 'errors' => [[]]],
        ],
    ]);
});

it('keeps dotted view references resolving as before', function (): void {
    $rule = new CallbackRule;
    $rule->setOptions(['componentPaths' => [__DIR__.'/../Fixtures/NativeComponents']]);

    (new RuleTester)->withFilePath('resources/views/native/user-card.blade.php')->run($rule, [
        'valid' => [],
        'invalid' => [
            ['code' => '<column><button @press="missingOnUserCard">x</button></column>', 'errors' => [[]]],
        ],
    ]);
});

it('does not infer ownership from view calls outside render', function (): void {
    $paths = [__DIR__.'/../Fixtures/NativeComponents'];

    $callback = new CallbackRule;
    $callback->setOptions(['componentPaths' => $paths]);
    (new RuleTester)->withFilePath('resources/views/native/not-owned.blade.php')->run($callback, [
        'valid' => ['<column><button @press="definitelyMissing">x</button></column>'],
        'invalid' => [
            ['code' => '<column><button @press="$expanded = false">x</button></column>', 'errors' => [[]]],
        ],
    ]);

    $model = new ModelPropertyRule;
    $model->setOptions(['componentPaths' => $paths]);
    (new RuleTester)->withFilePath('resources/views/native/not-owned.blade.php')->run($model, [
        'valid' => ['<column><row native:model="definitelyMissing" /></column>'],
        'invalid' => [],
    ]);

    (new RuleTester)->withFilePath('resources/views/native/actually-owned.blade.php')->run($callback, [
        'valid' => [],
        'invalid' => [['code' => '<column><button @press="missing">x</button></column>', 'errors' => [[]]]],
    ]);
});

it('refreshes callback ownership after source changes in the same process', function (): void {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sheath-native-callback-refresh-'.uniqid();
    mkdir($directory);
    $component = $directory.DIRECTORY_SEPARATOR.'Probe.php';
    $source = static fn (string $class): string => "<?php\nnamespace App\\NativeComponents;\nclass {$class} { public function render() { return view('native.refresh-callback'); } }\n";
    $rule = new CallbackRule;
    $rule->setOptions(['componentPaths' => [$directory]]);

    try {
        file_put_contents($component, $source('FixtureDrawerHome'));
        (new RuleTester)->withFilePath('resources/views/native/refresh-callback.blade.php')->run($rule, [
            'valid' => ['<column><button @press="openSettings">x</button></column>'],
            'invalid' => [],
        ]);

        file_put_contents($component, $source('UserCard'));
        clearstatcache(true, $component);
        $rule->cacheContext(['componentPaths' => [$directory]]);
        (new RuleTester)->withFilePath('resources/views/native/refresh-callback.blade.php')->run($rule, [
            'valid' => [],
            'invalid' => [['code' => '<column><button @press="openSettings">x</button></column>', 'errors' => [[]]]],
        ]);
    } finally {
        if (is_file($component)) {
            unlink($component);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

it('refreshes model ownership after source changes in the same process', function (): void {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sheath-native-model-refresh-'.uniqid();
    mkdir($directory);
    $component = $directory.DIRECTORY_SEPARATOR.'Probe.php';
    $source = static fn (string $class): string => "<?php\nnamespace App\\NativeComponents;\nclass {$class} { public function render() { return view('native.refresh-model'); } }\n";
    $rule = new ModelPropertyRule;
    $rule->setOptions(['componentPaths' => [$directory]]);

    try {
        file_put_contents($component, $source('VisibilityScreen'));
        (new RuleTester)->withFilePath('resources/views/native/refresh-model.blade.php')->run($rule, [
            'valid' => ['<column><row native:model="title" /></column>'],
            'invalid' => [],
        ]);

        file_put_contents($component, $source('FixtureDrawerHome'));
        clearstatcache(true, $component);
        $rule->cacheContext(['componentPaths' => [$directory]]);
        (new RuleTester)->withFilePath('resources/views/native/refresh-model.blade.php')->run($rule, [
            'valid' => [],
            'invalid' => [['code' => '<column><row native:model="title" /></column>', 'errors' => [[]]]],
        ]);
    } finally {
        if (is_file($component)) {
            unlink($component);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

it('judges tap-family aliases with press-family arity', function (): void {
    $rule = new CallbackRule;
    $rule->setOptions(['componentPaths' => [__DIR__.'/../Fixtures/NativeComponents']]);

    (new RuleTester)->withFilePath('resources/views/native/counter-demo.blade.php')->run($rule, [
        'valid' => [
            '<column><button @tap="viewProfile(3)">correct arity</button></column>',
            '<column><button @tapUp="increment">no params</button></column>',
        ],
        'invalid' => [
            ['code' => '<column><button @tap="viewProfile">profile</button></column>', 'errors' => [[]]],
            ['code' => '<column><button @longTap="moveItem">reorder</button></column>', 'errors' => [[]]],
            ['code' => '<column><button @tapDown="viewProfile">profile</button></column>', 'errors' => [[]]],
        ],
    ]);
});

it('splits the native:model verdict by property visibility', function (): void {
    $rule = new ModelPropertyRule;
    $rule->setOptions(['componentPaths' => [__DIR__.'/../Fixtures/NativeComponents']]);

    (new RuleTester)->withFilePath('resources/views/native/fixture-visibility.blade.php')->run($rule, [
        'valid' => [
            '<column><row native:model="title" /></column>',
        ],
        'invalid' => [
            ['code' => '<column><row native:model="draft" /></column>', 'errors' => 1],
            ['code' => '<column><row native:model="titel" /></column>', 'errors' => 1],
            ['code' => '<column><row native:model="accountId" /></column>', 'errors' => 1],
            ['code' => '<column><row native:model="immutable" /></column>', 'errors' => 1],
        ],
    ]);
});
