<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Tests\Unit;

use Forte\Sheath\NativePhp\Rules\Styling\BorderPairRule;
use Forte\Sheath\NativePhp\Rules\Styling\DarkVariantTargetRule;
use Forte\Sheath\NativePhp\Rules\Styling\DeadClassRule;
use Forte\Sheath\NativePhp\Rules\Styling\GlassModifierRule;
use Forte\Sheath\NativePhp\Rules\Styling\MisrenderClassRule;
use Forte\Sheath\NativePhp\Rules\Styling\PreferThemeTokensRule;
use Forte\Sheath\NativePhp\Rules\Styling\TypographyTargetRule;
use Forte\Sheath\Testing\RuleTester;

beforeEach(function (): void {
    config()->set('native-ui.theme', [
        'light' => [
            'surface' => '#FFFFFF',
            'on-surface' => '#000000',
            'primary' => '#6750A4',
            'outline' => '#79747E',
        ],
    ]);
});

it('delegates supported-class truth to the installed native parser', function (): void {
    (new RuleTester)->run(new DeadClassRule, [
        'valid' => [
            '<column class="w-full items-center gap-2 flex-1 flex-row safe-area"><text>x</text></column>',
            '<column class="bg-theme-surface text-theme-on-surface bg-theme-primary/15"><text>x</text></column>',
            '<column class="ios:bg-cyan-300/30 android:dark:bg-white"><text>x</text></column>',
            '<column class="glass:interactive rounded-2xl"><text>x</text></column>',
            '<column class="px-4 {{ $active ? \'bg-red-500\' : \'bg-white\' }}"><text>x</text></column>',
            '<column class="grid" class="p-4"><text>x</text></column>',
            '<native:webview><div class="grid-cols-3">raw web content</div></native:webview>',
        ],
        'invalid' => [
            ['code' => '<column class="grid-cols-3"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column class="hover:bg-red-500"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column class="felx-1"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column class="sm:px-4"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column @if($grid) class="grid-cols-3" @else class="p-4" @endif><text>x</text></column>', 'errors' => 1],
        ],
    ]);
});

it('reports accepted arbitrary values whose numeric meaning changes', function (): void {
    (new RuleTester)->run(new MisrenderClassRule, [
        'valid' => [
            '<column class="w-[320] max-w-[640] rounded-br-[12px] border-[#65676B] text-[14] p-[12] aspect-[16/9]"><text>x</text></column>',
            '<column class="opacity-[0.65] w-[50]"><text>x</text></column>',
            '<column class="dark:w-[50%]"><text>x</text></column>',
        ],
        'invalid' => [
            ['code' => '<column class="w-[50%]"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column class="rounded-br-[1rem]"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column class="-left-[10vw]"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column><text class="text-[red]">x</text></column>', 'errors' => 1],
            ['code' => '<column class="opacity-[65]"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column class="opacity-[150]"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column class="opacity-[-1]"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column class="aspect-[calc(4/3)]"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column><text class="text-base/7">x</text></column>', 'errors' => 1],
        ],
    ]);
});

it('reports dark payload keys the collector drops', function (): void {
    (new RuleTester)->run(new DarkVariantTargetRule, [
        'valid' => [
            '<column class="dark:bg-slate-900 dark:text-white dark:opacity-50"><text>x</text></column>',
            '<column class="dark:bg-theme-surface"><text>x</text></column>',
        ],
        'invalid' => [
            ['code' => '<column class="dark:rounded-lg"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column class="dark:font-bold"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column class="dark:-mt-4"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column class="ios:dark:bg-black ios:dark:rounded-lg"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column class="dark:rounded-lg dark:font-bold"><text>x</text></column>', 'errors' => 2],
        ],
    ]);
});

it('models border pairing, platform paths, class order, and attribute precedence', function (): void {
    (new RuleTester)->run(new BorderPairRule, [
        'valid' => [
            '<column class="border border-red-500"><text>x</text></column>',
            '<column class="border-theme-outline"><text>x</text></column>',
            '<column class="border-theme-outline border-2"><text>x</text></column>',
            '<column class="border-2 border-theme-primary border-4"><text>x</text></column>',
            '<column class="ios:border-2 ios:border-red-500 android:border-4 android:border-blue-500"><text>x</text></column>',
            '<column class="border-2 border-theme-primary" borderWidth="4"><text>x</text></column>',
            '<column class="border-2" borderColor="#fff"><text>x</text></column>',
            '<column @if($bordered) class="border-red-500" borderWidth="2" @else class="p-4" @endif><text>x</text></column>',
        ],
        'invalid' => [
            ['code' => '<column class="border-2"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column class="border-red-500"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column class="border-2 border-theme-primary"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column class="android:border-2"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column class="ios:border-2 android:border-red-500"><text>x</text></column>', 'errors' => 2],
            ['code' => '<column @if($bordered) class="border-red-500" @else borderWidth="2" @endif><text>x</text></column>', 'errors' => 2],
        ],
    ]);
});

it('reports glass modifiers the parser silently ignores', function (): void {
    (new RuleTester)->run(new GlassModifierRule, [
        'valid' => [
            '<column class="glass"><text>x</text></column>',
            '<column class="glass:prominent:interactive glass:clear"><text>x</text></column>',
        ],
        'invalid' => [
            ['code' => '<column class="glass:thicc"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column class="glass:clear:interactve"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column class="ios:glass:thicc"><text>x</text></column>', 'errors' => 1],
        ],
    ]);
});

it('keeps fixed-color guidance non-blocking and limited to interface color utilities', function (): void {
    (new RuleTester)->run(new PreferThemeTokensRule, [
        'valid' => [
            '<column class="bg-theme-surface text-theme-on-surface"><text>x</text></column>',
            '<column class="bg-sky-500"><text>palette classes are allowed</text></column>',
            '<column class="bg-[#12345] text-[#1234567]"><text>invalid hex belongs to dead-class</text></column>',
        ],
        'invalid' => [
            ['code' => '<column class="bg-[#1E2021]"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column @if($hardcoded) class="bg-[#ffffff]" @else class="bg-theme-surface" @endif><text>x</text></column>', 'errors' => 1],
        ],
    ]);
});

it('reports typography where the renderer has no text consumer', function (): void {
    (new RuleTester)->run(new TypographyTargetRule, [
        'valid' => [
            '<column class="p-4 bg-white"><text class="text-lg font-bold text-white">x</text></column>',
            '<column><button class="font-bold text-center">Go</button></column>',
            '<column><icon name="star" class="text-red-500 dark:text-white" /></column>',
            '<column><activity-indicator class="text-white" /></column>',
            '<column><chip class="text-sm">plugin elements stand down</chip></column>',
        ],
        'invalid' => [
            ['code' => '<column class="text-white"><text>x</text></column>', 'errors' => 1],
            ['code' => '<row class="font-bold"><text>x</text></row>', 'errors' => 1],
            ['code' => '<column class="text-center"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column><image src="/a.png" class="text-lg" /></column>', 'errors' => 1],
            ['code' => '<column class="dark:text-white dark:text-lg"><text>x</text></column>', 'errors' => 2],
        ],
    ]);
});
