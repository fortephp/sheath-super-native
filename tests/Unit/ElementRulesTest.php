<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Tests\Unit;

use Forte\Sheath\NativePhp\Rules\Elements\DiscardedMarkupRule;
use Forte\Sheath\NativePhp\Rules\Elements\InvalidEnumValueRule;
use Forte\Sheath\NativePhp\Rules\Elements\UnknownElementRule;
use Forte\Sheath\NativePhp\Rules\Guidelines\StyleAttributeRule;
use Forte\Sheath\NativePhp\Rules\Interaction\ModelModifierRule;
use Forte\Sheath\NativePhp\Rules\Interaction\ModelTargetRule;
use Forte\Sheath\NativePhp\Rules\Interaction\NavigateTransitionRule;
use Forte\Sheath\NativePhp\Rules\Interaction\UnsupportedEventRule;
use Forte\Sheath\Testing\RuleTester;

it('separates registered native tags, unknown prefixed tags, and discarded bare markup', function (): void {
    (new RuleTester)->run(new UnknownElementRule, [
        'valid' => [
            '<native:column><native:text>x</native:text></native:column>',
            '<native:scroll-view><native:icon name="star" /></native:scroll-view>',
        ],
        'invalid' => [
            ['code' => '<native:columm />', 'errors' => 1],
            ['code' => '<native:column><native:date-picker /></native:column>', 'errors' => 1],
        ],
    ]);

    (new RuleTester)->run(new DiscardedMarkupRule, [
        'valid' => [
            '<column><text>x</text><x-alert /></column>',
            '<native:webview><div><p>raw web content</p></div></native:webview>',
            '<div><p>a plain web view is outside native scope</p></div>',
        ],
        'invalid' => [
            ['code' => '<column><columm><text>x</text></columm></column>', 'errors' => 1],
            ['code' => '<column><div><text>x</text></div></column>', 'errors' => 1],
            ['code' => '<column><webview><div><p>not a registered raw slot</p></div></webview></column>', 'errors' => 3],
        ],
    ]);
});

it('follows installed enum parsing and the scroll axis exact-match contract', function (): void {
    (new RuleTester)->run(new InvalidEnumValueRule, [
        'valid' => [
            '<column alignItems="middle" justifyContent="between"><text textAlign="leading">x</text></column>',
            '<column flexDirection><text>x</text></column>',
            '<column flexDirection=""><text>x</text></column>',
            '<column flexDirection="row"><text>x</text></column>',
            '<column flexDirection="1foo"><text>x</text></column>',
            '<column flexDirection="2"><text>x</text></column>',
            '<column :justifyContent="$j" alignItems="{{ $a }}"><text>x</text></column>',
            '<column textAlign="justify"><text>x</text></column>',
            '<scroll-view axis="both"><text>x</text></scroll-view>',
            '<scroll-view axis="diagonal" axis="vertical"><text>x</text></scroll-view>',
            '<scroll-view axis="diagonal" :axis="$axis"><text>x</text></scroll-view>',
        ],
        'invalid' => [
            ['code' => '<column alignItems="middle-ish"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column alignItems="0"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column justifyContent="stretch"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column><text textAlign="justify">x</text></column>', 'errors' => 1],
            ['code' => '<column alignItems><text>x</text></column>', 'errors' => 1],
            ['code' => '<scroll-view axis="Horizontal"><text>x</text></scroll-view>', 'errors' => 1],
            ['code' => '<scroll-view axis><text>x</text></scroll-view>', 'errors' => 1],
            ['code' => '<scroll-view @if($bad) axis="diagonal" @else axis="both" @endif><text>x</text></scroll-view>', 'errors' => 1],
        ],
    ]);
});

it('validates navigate modifiers after compiler-effective attribute resolution', function (): void {
    (new RuleTester)->run(new NavigateTransitionRule, [
        'valid' => [
            "<column @navigate.slideFromRight('/detail')><text>x</text></column>",
            '<column @navigate.back><text>x</text></column>',
            '<column @navigate.replace.fade="/route"><text>x</text></column>',
            '<column @navigate.slideFromRigt="/old" @navigate.fade="/route"><text>x</text></column>',
            '<column @navigate.slideFromRigt="/old" :_navigate="$route"><text>x</text></column>',
        ],
        'invalid' => [
            ['code' => "<column @navigate.slideFromRigt('/detail')><text>x</text></column>", 'errors' => [['hasFix' => true]]],
            ['code' => '<column @navigate.slide="/route"><text>x</text></column>', 'errors' => 1],
            ['code' => "<column @if(\$bad) @navigate.slideFromRigt('/detail') @endif><text>x</text></column>", 'errors' => [['hasFix' => true]]],
        ],
    ]);
});

it('reports only events the target cannot dispatch', function (): void {
    (new RuleTester)->run(new UnsupportedEventRule, [
        'valid' => [
            '<column @press="save" @longTap="peek"><text>x</text></column>',
            '<column><toggle @change="flip" /></column>',
            '<column><refreshable @refresh="load"><text>x</text></refreshable></column>',
            '<column @class([\'p-4\' => $padded])><text>x</text></column>',
        ],
        'invalid' => [
            ['code' => '<column @change="save"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column><text @refresh="load">x</text></column>', 'errors' => 1],
            ['code' => '<column><list-item @trailingPress="save" /></column>', 'errors' => 1],
            ['code' => '<column><text @if($bad) @refresh="load" @else @press="open" @endif>x</text></column>', 'errors' => 1],
        ],
    ]);
});

it('allows model bindings only where reflected change dispatch exists', function (): void {
    (new RuleTester)->run(new ModelTargetRule, [
        'valid' => [
            '<column><outlined-text-input native:model="query" /></column>',
            '<column><text-input native:model.debounce.150ms="query" /></column>',
            '<column><toggle @model="enabled" /></column>',
        ],
        'invalid' => [
            ['code' => '<column native:model="query"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column><text native:model.blur="query">x</text></column>', 'errors' => 1],
            ['code' => '<column><fab native:model="query" /></column>', 'errors' => 1],
            ['code' => '<column @if($sync) native:model="query" @endif><text>x</text></column>', 'errors' => 1],
        ],
    ]);
});

it('rejects model syntax that compiles to missing or altered synchronization', function (): void {
    (new RuleTester)->run(new ModelModifierRule, [
        'valid' => [
            '<column><row @model="query" /></column>',
            '<column><row native:model="query" /></column>',
            '<column><row native:model.live="query" /></column>',
            '<column><row native:model.blur="query" /></column>',
            '<column><row native:model.lazy="query" /></column>',
            '<column><row native:model.debounce="query" /></column>',
            '<column><row native:model.debounce.150ms="query" /></column>',
            '<column><row native:model.debonce.500ms="old" native:model="query" /></column>',
        ],
        'invalid' => [
            ['code' => '<column><row native:model /></column>', 'errors' => 1],
            ['code' => '<column><row native:model="" /></column>', 'errors' => 1],
            ['code' => '<column><row :native:model="$property" /></column>', 'errors' => 1],
            ['code' => '<column><row native:model="query.name" /></column>', 'errors' => 1],
            ['code' => '<column><row native:model.debounce.0ms="query" /></column>', 'errors' => 1],
            ['code' => '<column><row @model.debounce.300ms="query" /></column>', 'errors' => 1],
            ['code' => '<column><row native:model.debonce.500ms="query" /></column>', 'errors' => 1],
            ['code' => '<column><row native:model.debounce.nope="query" /></column>', 'errors' => 1],
            ['code' => '<column><row native:model.blur.extra="query" /></column>', 'errors' => 1],
        ],
    ]);
});

it('limits style-attribute guidance to native-rendered elements', function (): void {
    (new RuleTester)->run(new StyleAttributeRule, [
        'valid' => [
            '<column><native:webview><div style="height: 100%">x</div></native:webview></column>',
            '<column class="p-4"><text>x</text></column>',
            '<column><skia-rect style="fill" /></column>',
        ],
        'invalid' => [
            ['code' => '<column style="border: 1px solid #eee"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column Style="border: 1px solid #eee"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column @if($styled) style="color: red" @endif><text>x</text></column>', 'errors' => 1],
        ],
    ]);

    (new RuleTester)->withFilePath('resources/views/checkout.blade.php')->run(new StyleAttributeRule, [
        'valid' => ['<div><spacer style="height: 4px" /><p>web checkout</p></div>'],
        'invalid' => [],
    ]);
});
