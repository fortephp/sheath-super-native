<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Tests\Unit;

use App\NativeComponents\UserCard;
use Forte\Sheath\NativePhp\Rules\Elements\ComponentSlotContentRule;
use Forte\Sheath\NativePhp\Rules\Elements\DiscardedMarkupRule;
use Forte\Sheath\NativePhp\Rules\Elements\UnknownAttributeRule;
use Forte\Sheath\NativePhp\Rules\Elements\UnknownElementRule;
use Forte\Sheath\NativePhp\Rules\Guidelines\StyleAttributeRule;
use Forte\Sheath\NativePhp\Rules\Interaction\CallbackRule;
use Forte\Sheath\NativePhp\Rules\Interaction\KeyHygieneRule;
use Forte\Sheath\NativePhp\Rules\Interaction\ModelTargetRule;
use Forte\Sheath\NativePhp\Rules\Interaction\UnsupportedEventRule;
use Forte\Sheath\NativePhp\Rules\Styling\BorderPairRule;
use Forte\Sheath\NativePhp\Rules\Styling\DeadClassRule;
use Forte\Sheath\NativePhp\Rules\Styling\UnknownThemeTokenRule;
use Forte\Sheath\NativePhp\Support\ComponentViewMap;
use Forte\Sheath\NativePhp\Support\ElementCatalog;
use Forte\Sheath\NativePhp\Support\PrecompilerOracle;
use Forte\Sheath\NativePhp\Support\ReflectedElementAttributes;
use Forte\Sheath\NativePhp\Support\TailwindOracle;
use Forte\Sheath\NativePhp\Tests\Fixtures\ReflectedButtonFixture;
use Forte\Sheath\NativePhp\Tests\Fixtures\ReflectedDynamicAttributesFixture;
use Forte\Sheath\NativePhp\Tests\Fixtures\ReflectedEventFixture;
use Forte\Sheath\NativePhp\Tests\Fixtures\ReflectedStyledFixture;
use Forte\Sheath\NativePhp\Tests\Fixtures\StyleChildFixture;
use Forte\Sheath\Testing\RuleTester;
use Native\Mobile\Edge\ComponentRegistry;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\TailwindParser;

beforeEach(function (): void {
    ElementRegistry::reset();
    ComponentRegistry::reset();
    ElementCatalog::reset();
    PrecompilerOracle::reset();
    ReflectedElementAttributes::reset();
    TailwindParser::setThemeResolver(null);
    TailwindParser::setThemeDarkResolver(null);
    TailwindOracle::reset();
    NativeElementCollector::stopCapturingAttributes();

    ElementRegistry::register('column', Column::class);
    ElementRegistry::register('text', Text::class);
    ComponentRegistry::register('user-card', UserCard::class);
});

afterEach(function (): void {
    ElementRegistry::reset();
    ComponentRegistry::reset();
    ElementCatalog::reset();
    PrecompilerOracle::reset();
    ReflectedElementAttributes::reset();
    TailwindParser::setThemeResolver(null);
    TailwindParser::setThemeDarkResolver(null);
    TailwindOracle::reset();
    NativeElementCollector::stopCapturingAttributes();
});

it('reads the tag universe from the static registries when populated', function (): void {
    (new RuleTester)->run(new UnknownElementRule, [
        'valid' => [
            '<native:column><native:user-card /></native:column>',
        ],
        'invalid' => [
            ['code' => '<native:column><native:usercard /></native:column>', 'errors' => 1],
        ],
    ]);
});

it('accepts collector builtins the registry never lists', function (): void {
    (new RuleTester)->run(new UnknownElementRule, [
        'valid' => [
            '<native:column><native:bottom-bar><native:text>input</native:text></native:bottom-bar></native:column>',
        ],
        'invalid' => [
            [
                'code' => '<native:column><native:bottom-barr /></native:column>',
                'errors' => [['hasFix' => true]],
            ],
        ],
    ]);
});

it('does not mistake reserved collector names for renderable elements', function (): void {
    expect(ElementCatalog::isKnownTag('button'))->toBeFalse()
        ->and(ElementCatalog::isKnownTag('webview'))->toBeFalse()
        ->and(ElementCatalog::isKnownTag('virtual-list'))->toBeFalse();

    ComponentRegistry::register('button', UserCard::class);
    ElementCatalog::reset();

    expect(ElementCatalog::isKnownTag('button'))->toBeFalse()
        ->and(ElementCatalog::componentTags())->not->toHaveKey('button');

    ElementRegistry::register('button', ReflectedButtonFixture::class);
    ElementCatalog::reset();

    expect(ElementCatalog::isKnownTag('button'))->toBeTrue()
        ->and(ElementCatalog::componentTags())->not->toHaveKey('button');
});

it('keeps collector-only tags out of the bare-tag precompiler allowlist', function (): void {
    expect(ElementCatalog::isKnownTag('bottom-bar'))->toBeTrue()
        ->and(ElementCatalog::shortFormTags())->not->toContain('bottom-bar')
        ->and(PrecompilerOracle::isCompiledAway('bottom-bar'))->toBeFalse();
});

it('reports element content inside a child component slot', function (): void {
    ComponentRegistry::register('user-card-alt', UserCard::class);
    ElementCatalog::reset();

    (new RuleTester)->run(new ComponentSlotContentRule, [
        'valid' => [
            '<column><native:user-card /></column>',
            '<column><native:user-card title="Plain text attributes are fine" /></column>',
            '<column><native:user-card><div>raw HTML does not enter the collector</div></native:user-card></column>',
            '<column><user-card><text>bare component tags are inert wrappers</text></user-card></column>',
        ],
        'invalid' => [
            ['code' => '<column><native:user-card><text>x</text></native:user-card></column>', 'errors' => 1],
            ['code' => '<column><native:user-card><native:user-card-alt /></native:user-card></column>', 'errors' => 1],
            ['code' => '<column><native:user-card><div><text>x</text></div></native:user-card></column>', 'errors' => 1],
            ['code' => '<column><native:user-card>@if($show)<text>x</text>@endif</native:user-card></column>', 'errors' => 1],
            ['code' => '<column><native:user-card>@foreach($rows as $row)<native:row />@endforeach</native:user-card></column>', 'errors' => 1],
        ],
    ]);
});

it('treats registered child components as prefix-only compiler syntax', function (): void {
    (new RuleTester)->run(new DiscardedMarkupRule, [
        'valid' => ['<column><native:user-card /></column>'],
        'invalid' => [[
            'code' => '<column><user-card title="inert" /></column>',
            'errors' => 1,
        ]],
    ]);

    (new RuleTester)->run(new UnknownElementRule, [
        'valid' => [],
        'invalid' => [[
            'code' => '<user-card><native:not-real /></user-card>',
            'errors' => 1,
        ]],
    ]);

    (new RuleTester)->run(new DiscardedMarkupRule, [
        'valid' => [],
        'invalid' => [[
            'code' => '<column><webview><div>not a raw slot without registration</div></webview></column>',
            'errors' => 2,
        ]],
    ]);
});

it('leaves diagnostics inside a rejected component slot to the slot rule', function (): void {
    (new RuleTester)->run(new UnknownElementRule, [
        'valid' => ['<native:user-card><native:not-real /></native:user-card>'],
        'invalid' => [],
    ]);

    (new RuleTester)->run(new UnknownAttributeRule, [
        'valid' => ['<native:user-card><column not-real="x" /></native:user-card>'],
        'invalid' => [],
    ]);

    (new RuleTester)->run(new DeadClassRule, [
        'valid' => ['<native:user-card><column class="grid-cols-3" /></native:user-card>'],
        'invalid' => [],
    ]);

    (new RuleTester)->run(new BorderPairRule, [
        'valid' => ['<native:user-card><column class="border-2" /></native:user-card>'],
        'invalid' => [],
    ]);

    (new RuleTester)->run(new DiscardedMarkupRule, [
        'valid' => ['<native:user-card><div><column /></div></native:user-card>'],
        'invalid' => [],
    ]);

    (new RuleTester)->run(new KeyHygieneRule, [
        'valid' => ['<native:user-card><column native:key /></native:user-card>'],
        'invalid' => [],
    ]);
});

it('uses key rather than native:key for child-component identity', function (): void {
    ComponentRegistry::register('user-card-alt', UserCard::class);
    ElementCatalog::reset();

    (new RuleTester)->run(new KeyHygieneRule, [
        'valid' => [
            '<column><native:user-card :key="$user->id" /></column>',
            '<column><native:user-card key="same" /></column><column><native:user-card-alt key="same" /></column>',
            '<column><native:user-card @if($compact) key="same" @else key="same" @endif /></column>',
        ],
        'invalid' => [
            ['code' => '<column><native:user-card native:key="user" /></column>', 'errors' => 1],
            ['code' => '<column>@foreach($users as $user)<native:user-card key="user" />@endforeach</column>', 'errors' => 1],
            ['code' => '<column>@foreach($users as $user)<native:user-card />@endforeach</column>', 'errors' => 1],
            ['code' => '<column>@foreach($users as $user)<native:user-card @if($keyed) :key="$user->id" @endif />@endforeach</column>', 'errors' => 1],
            ['code' => '<column><native:user-card key="dup" /><row><native:user-card key="dup" /></row></column>', 'errors' => 1],
        ],
    ]);
});

it('allows arbitrary event bindings on registered child components', function (): void {
    (new RuleTester)->run(new UnsupportedEventRule, [
        'valid' => [
            '<column><native:user-card @saved="refresh" /></column>',
        ],
        'invalid' => [
            ['code' => '<column><native:user-card @press="refresh" /></column>', 'errors' => 1],
            ['code' => '<column><native:user-card @change="refresh" /></column>', 'errors' => 1],
        ],
    ]);

    $callbacks = new CallbackRule;
    $callbacks->setOptions(['componentPaths' => [__DIR__.'/../Fixtures/NativeComponents']]);
    (new RuleTester)->withFilePath('resources/views/native/counter-demo.blade.php')->run($callbacks, [
        'valid' => ['<column><native:user-card @press="definitelyMissing" /></column>'],
        'invalid' => [],
    ]);
});

it('rejects native model bindings on child components', function (): void {
    (new RuleTester)->run(new ModelTargetRule, [
        'valid' => [],
        'invalid' => [
            ['code' => '<column><native:user-card native:model="query" /></column>', 'errors' => 1],
            ['code' => '<column><native:user-card @model="query" /></column>', 'errors' => 1],
        ],
    ]);
});

it('reflects change support when deciding whether an element can model', function (): void {
    ElementRegistry::register('input_probe', ReflectedEventFixture::class);
    ElementRegistry::register('display_probe', Column::class);
    ElementCatalog::reset();

    (new RuleTester)->run(new ModelTargetRule, [
        'valid' => ['<column><native:input-probe native:model="query" /></column>'],
        'invalid' => [[
            'code' => '<column><native:display-probe native:model="query" /></column>',
            'errors' => 1,
        ]],
    ]);
});

it('uses zero payload arguments for reflected end-reached handlers', function (): void {
    ElementRegistry::register('end_probe', ReflectedEventFixture::class);
    ElementCatalog::reset();
    ComponentViewMap::reset();

    $rule = new CallbackRule;
    $rule->setOptions(['componentPaths' => [__DIR__.'/../Fixtures/NativeComponents']]);

    (new RuleTester)->withFilePath('resources/views/native/counter-demo.blade.php')->run($rule, [
        'valid' => [
            '<column><native:end-probe @endReached="increment" /></column>',
            '<column><native:end-probe @swipeDelete="increment" /></column>',
        ],
        'invalid' => [
            [
                'code' => '<column><native:end-probe @endReached="oneEventArgument" /></column>',
                'errors' => 1,
            ],
            [
                'code' => '<column><native:end-probe @swipeDelete="oneEventArgument" /></column>',
                'errors' => 1,
            ],
        ],
    ]);
});

it('uses registered handler methods for native event support', function (): void {
    ElementRegistry::register('eventful_widget', ReflectedEventFixture::class);
    ElementRegistry::register('eventless_widget', ReflectedButtonFixture::class);
    ElementCatalog::reset();

    (new RuleTester)->run(new UnsupportedEventRule, [
        'valid' => ['<column><eventful-widget @change="save" /></column>'],
        'invalid' => [[
            'code' => '<column><eventless-widget @change="save" /></column>',
            'errors' => 1,
        ]],
    ]);

    (new RuleTester)->run(new UnknownAttributeRule, [
        'valid' => ['<column><eventful-widget _change="save" /></column>'],
        'invalid' => [],
    ]);
});

it('validates theme tokens against the application theme config', function (): void {
    config()->set('native-ui.theme', [
        'light' => ['primary' => 'violet-600', 'surface' => 'white', 'on-surface' => 'slate-900'],
        'dark' => ['night-only' => 'black'],
    ]);

    (new RuleTester)->run(new UnknownThemeTokenRule, [
        'valid' => [
            '<column class="bg-theme-surface text-theme-on-surface bg-theme-primary/15"><text>x</text></column>',
            '<column class="dark:bg-theme-surface"><text>x</text></column>',
        ],
        'invalid' => [
            ['code' => '<column class="bg-theme-surfase"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column class="bg-theme-Surface"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column class="bg-theme-night-only"><text>x</text></column>', 'errors' => 1],
        ],
    ]);
});

it('uses the installed runtime theme resolver as the compiler authority', function (): void {
    config()->set('native-ui.theme', [
        'light' => ['config-only' => '#111111'],
    ]);
    TailwindParser::setThemeResolver(
        static fn (string $token): ?string => $token === 'runtime-only' ? '#123456' : null
    );
    TailwindOracle::reset();

    (new RuleTester)->run(new UnknownThemeTokenRule, [
        'valid' => [
            '<column class="bg-theme-runtime-only"><text>x</text></column>',
            '<column class="ios:android:bg-theme-config-only"><text>x</text></column>',
        ],
        'invalid' => [[
            'code' => '<column class="bg-theme-config-only"><text>x</text></column>',
            'errors' => 1,
        ]],
    ]);
});

it('reports theme classes when neither resolver nor config supplies a token space', function (): void {
    config()->set('native-ui.theme');
    TailwindParser::setThemeResolver(null);
    TailwindParser::setThemeDarkResolver(null);
    TailwindOracle::reset();

    (new RuleTester)->run(new UnknownThemeTokenRule, [
        'valid' => [],
        'invalid' => [[
            'code' => '<column class="bg-theme-no-resolver"><text>x</text></column>',
            'errors' => 1,
        ]],
    ]);

    (new RuleTester)->run(new DeadClassRule, [
        'valid' => ['<column class="bg-theme-no-resolver"><text>x</text></column>'],
        'invalid' => [],
    ]);
});

it('reflects registered plugin attribute hydrators without guessing unknown plugins', function (): void {
    ElementRegistry::register('button', ReflectedButtonFixture::class);
    ElementRegistry::register('styled_widget', ReflectedStyledFixture::class);
    ElementRegistry::register('dynamic_widget', ReflectedDynamicAttributesFixture::class);
    ElementCatalog::reset();
    ReflectedElementAttributes::reset();

    (new RuleTester)->run(new UnknownAttributeRule, [
        'valid' => [
            '<column><button label="Save" icon="check" a11y-label="Save" /></column>',
            '<column><unregistered-widget whatever="third parties stand down" /></column>',
            '<column><dynamic-widget foo="runtime-supported" /></column>',
        ],
        'invalid' => [
            ['code' => '<column><button color="#fff" labelColor="#000" :fontSize="14" /></column>', 'errors' => 3],
        ],
    ]);

    (new RuleTester)->run(new StyleAttributeRule, [
        'valid' => [
            '<column><styled-widget style="plugin-owned" /></column>',
            '<column><dynamic-widget style="possibly-runtime-supported" /></column>',
        ],
        'invalid' => [],
    ]);
});

it('augments a bundled attribute fallback with the registered hydrator', function (): void {
    ElementRegistry::register('column', ReflectedButtonFixture::class);
    ElementCatalog::reset();
    ReflectedElementAttributes::reset();

    (new RuleTester)->run(new UnknownAttributeRule, [
        'valid' => ['<column label="runtime-added"><text>x</text></column>'],
        'invalid' => [],
    ]);
});

it('accepts attributes registered through the collector capture API', function (): void {
    NativeElementCollector::captureAttribute('track', 'analytics_id');

    (new RuleTester)->run(new UnknownAttributeRule, [
        'valid' => ['<column track="signup"><text>x</text></column>'],
        'invalid' => [],
    ]);
});

it('leaves style props on registered child components to applyChildProps', function (): void {
    ComponentRegistry::register('style-child', StyleChildFixture::class);
    ElementCatalog::reset();

    (new RuleTester)->run(new StyleAttributeRule, [
        'valid' => ['<column><native:style-child style="compact" /></column>'],
        'invalid' => [],
    ]);

    (new RuleTester)->run(new DeadClassRule, [
        'valid' => ['<column><native:style-child class="component-owned-token" /></column>'],
        'invalid' => [],
    ]);
});

it('honours configured native view paths', function (): void {
    $rule = new StyleAttributeRule;

    (new RuleTester)->withFilePath('resources/screens/home.blade.php')->run($rule, [
        'valid' => [
            '<text style="color: red">not detectably native without the path hint</text>',
        ],
        'invalid' => [],
    ]);

    $rule->setOptions(['nativeViewPaths' => ['screens/']]);

    (new RuleTester)->withFilePath('resources/views/web/page.blade.php')->run($rule, [
        'valid' => [
            '<bottom-sheet style="display: block">web component collision</bottom-sheet>',
        ],
        'invalid' => [],
    ]);

    (new RuleTester)->withFilePath('resources/screens/home.blade.php')->run($rule, [
        'valid' => [],
        'invalid' => [
            ['code' => '<text style="color: red">now in scope</text>', 'errors' => 1],
        ],
    ]);
});
