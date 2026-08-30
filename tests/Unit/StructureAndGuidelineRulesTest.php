<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Tests\Unit;

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Linter;
use Forte\Sheath\NativePhp\Rules\Elements\StructureRule;
use Forte\Sheath\NativePhp\Rules\Elements\UnknownAttributeRule;
use Forte\Sheath\NativePhp\Rules\Guidelines\IconAccessibleLabelRule;
use Forte\Sheath\NativePhp\Rules\Guidelines\NoEmojiRule;
use Forte\Sheath\NativePhp\Rules\Guidelines\SafeAreaChromeRule;
use Forte\Sheath\NativePhp\Rules\Interaction\KeyHygieneRule;
use Forte\Sheath\Packages\Dependencies;
use Forte\Sheath\Rules\RuleRegistry;
use Forte\Sheath\Testing\RuleTester;

it('requires stable identity on every compiler-visible loop path', function (): void {
    (new RuleTester)->run(new KeyHygieneRule, [
        'valid' => [
            '<column>@foreach ($rows as $row)<row :native:key="$row[\'id\']"><text>{{ $row[\'label\'] }}</text></row>@endforeach</column>',
            '<column native:key="left"><row native:key="item" /></column><column native:key="right"><row native:key="item" /></column>',
            '<column>@if($compact)<row native:key="same" />@else<row native:key="same" />@endif</column>',
            '<column><row @if($compact) native:key="same" @else native:key="same" @endif /></column>',
            '<column>@foreach ($rows as $row)<row @if($compact) :native:key="$row[\'compact_id\']" @else :native:key="$row[\'id\']" @endif /></column>',
            '<column>@foreach ($rows as $row)<row @if(complex($row)) :native:key="$row[\'id\']" @endif @if(other($row)) :native:key="$row[\'other_id\']" @endif /></column>',
            '<column native:key native-key="screen"><text>x</text></column>',
        ],
        'invalid' => [
            ['code' => '<column native:key><text>x</text></column>', 'errors' => 1],
            ['code' => '<column>@foreach ($rows as $row)<row native:key="row" />@endforeach</column>', 'errors' => 1],
            ['code' => '<column>@foreach ($rows as $row)<row :native:key="$loop->index" />@endforeach</column>', 'errors' => 1],
            ['code' => '<column><row native:key="dup" /><row native:key="dup" /></column>', 'errors' => 1],
            ['code' => '<column>@foreach ($rows as $row)<row><text>x</text></row>@endforeach</column>', 'errors' => 1],
            ['code' => '<column>@foreach ($rows as $row)<row @if($keyed) :native:key="$row[\'id\']" @endif /></column>', 'errors' => 1],
            ['code' => '<column><row key="item" /></column>', 'errors' => 1],
        ],
    ]);
});

it('matches chrome item ownership and virtual-list render prerequisites', function (): void {
    (new RuleTester)->run(new StructureRule, [
        'valid' => [
            '<bottom-nav><bottom-nav-item id="home" url="/" /></bottom-nav>',
            '<top-bar><top-bar-action id="more"><top-bar-action id="nested" /></top-bar-action><top-bar-title><text>Logo</text></top-bar-title></top-bar>',
            '<side-nav><side-nav-header title="Menu" /><side-nav-group heading="More"><side-nav-item label="Home" /></side-nav-group><side-nav-item label="Settings" /></side-nav>',
            '<column><native:virtual-list item="native.row" :count="$count" /></column>',
            '<column><native:virtual-list item="native.row" count /></column>',
        ],
        'invalid' => [
            ['code' => '<column><bottom-nav-item id="home" /></column>', 'errors' => 1],
            ['code' => '<column><side-nav-item label="Home" /></column>', 'errors' => 1],
            ['code' => '<side-nav><column><side-nav-group heading="More" /></column></side-nav>', 'errors' => 1],
            ['code' => '<column><native:virtual-list :count="10" /></column>', 'errors' => 1],
            ['code' => '<column><native:virtual-list item="0" :count="10" /></column>', 'errors' => 1],
            ['code' => '<column><native:virtual-list item="native.row" count="0" /></column>', 'errors' => 1],
            ['code' => '<column><native:virtual-list item="native.row" count="many" /></column>', 'errors' => 1],
            ['code' => '<column><native:virtual-list /></column>', 'errors' => 2],
        ],
    ]);
});

it('reports only interactive icon controls lacking a usable name', function (): void {
    (new RuleTester)->run(new IconAccessibleLabelRule, [
        'valid' => [
            '<column><icon name="send" /></column>',
            '<column><icon @press="send" name="send" a11y-label="Send" /></column>',
            '<column><pressable @press="send"><text>Send</text><icon name="send" /></pressable></column>',
            '<column><fab icon="pencil" /></column>',
            '<column><fab icon="pencil" url="/compose" label="Compose" /></column>',
            '<column><fab icon="pencil" @if($extended) label="Compose" @else a11y-label="Compose" @endif url="/compose" /></column>',
            '<column><pressable @press="send" a11y-label="" a11y-label="Send"><icon name="send" /></pressable></column>',
            '<column><icon @if($active) @press="send" a11y-label="Send" @else name="send" @endif /></column>',
        ],
        'invalid' => [
            ['code' => '<column><pressable @press="send"><icon name="send" /></pressable></column>', 'errors' => 1],
            ['code' => '<column><icon @press="send" name="send" /></column>', 'errors' => 1],
            ['code' => '<column><fab icon="pencil" url="/compose" /></column>', 'errors' => 1],
            ['code' => '<column><fab icon="pencil" url="/compose" @if($extended) label="Compose" @endif /></column>', 'errors' => 1],
            ['code' => '<column><icon @press="send" name="send" a11y-label="   " /></column>', 'errors' => 1],
            ['code' => '<column><pressable @press="send" a11y-label="Send" a11y-label=""><icon name="send" /></pressable></column>', 'errors' => 1],
        ],
    ]);
});

it('keeps the no-emoji policy scoped to rendered UI text and labels', function (): void {
    (new RuleTester)->run(new NoEmojiRule, [
        'valid' => [
            '<column><text>Settings</text><button>Save</button></column>',
            '<column><text>{{ $userMessage }}</text></column>',
            "<column><text>\u{2713} Complete \u{00B7} A \u{2192} B</text></column>",
            "<column><top-bar-action label=\"\u{1F680} Launch\" label=\"Launch\" /></column>",
        ],
        'invalid' => [
            ['code' => "<column><button>\u{1F680} Launch</button></column>", 'errors' => 1],
            ['code' => "<column><text>Settings \u{2699}\u{FE0F}</text></column>", 'errors' => 1],
            ['code' => "<column><top-bar-action label=\"\u{1F680} Launch\" /></column>", 'errors' => 1],
            ['code' => "<column><text>Flag \u{1F1FA}\u{1F1F8}</text></column>", 'errors' => 1],
            ['code' => "<column><text>Press 1\u{FE0F}\u{20E3}</text></column>", 'errors' => 1],
        ],
    ]);
});

it('reports only safe-area edges already owned by hoisted native chrome', function (): void {
    (new RuleTester)->run(new SafeAreaChromeRule, [
        'valid' => [
            '<column class="safe-area"><text>chrome-less screen</text></column>',
            '<column><top-bar title="Home" custom /><column class="safe-area-top"><text>x</text></column></column>',
            '<column><top-bar title="Home" /><column class="safe-area-bottom"><text>x</text></column></column>',
            '<column><bottom-nav><bottom-nav-item label="Home" /></bottom-nav><column class="safe-area-top"><text>x</text></column></column>',
            '<column class="safe-area-bottom"><native:bottom-bar><row><text>Composer</text></row></native:bottom-bar></column>',
            '<column class="safe-area-top"><row><top-bar title="Nested" /></row><text>x</text></column>',
            '<column>@if($chrome)<top-bar title="Home" />@else<column class="safe-area-top"><text>x</text></column>@endif</column>',
        ],
        'invalid' => [
            ['code' => '<column class="safe-area"><top-bar title="Home" /><text>x</text></column>', 'errors' => 1],
            ['code' => '<column class="safe-area-top"><top-bar title="Home" :custom="false" /><text>x</text></column>', 'errors' => 1],
            ['code' => '<column class="ios:safe-area-top"><top-bar title="Home" /><text>x</text></column>', 'errors' => 1],
            ['code' => '<column class="android:safe-area"><bottom-nav><bottom-nav-item label="Home" /></bottom-nav></column>', 'errors' => 1],
            ['code' => '<column class="safe-area-bottom"><top-bar title="Compose" /><native:bottom-bar><row><text>Composer</text></row></native:bottom-bar></column>', 'errors' => 1],
        ],
    ]);
});

it('tracks the Android stack bottom-bar inset introduced in NativePHP 4.3', function (): void {
    $violationsFor = function (string $version, string $source): int {
        $rule = new SafeAreaChromeRule;
        $registry = new RuleRegistry;
        $registry->register($rule);

        $dependencies = Dependencies::fromData(
            ['require' => ['nativephp/mobile' => '^4.2']],
            ['packages' => [['name' => 'nativephp/mobile', 'version' => $version]]],
        );
        $config = Config::make()->setRule($rule->getId(), ['severity' => 'warning']);
        $result = new Linter($registry, $dependencies)->lint(
            $source,
            'resources/views/native/safe-area.blade.php',
            $config,
        );

        return count(array_filter(
            $result->violations,
            fn ($violation): bool => $violation->ruleId === $rule->getId(),
        ));
    };

    $stack = fn (string $class): string => <<<BLADE
        <column class="{$class}">
            <top-bar title="Compose" />
            <native:bottom-bar><row><text>Composer</text></row></native:bottom-bar>
        </column>
        BLADE;
    $tabs = <<<'BLADE'
        <column class="android:safe-area-bottom">
            <bottom-nav><bottom-nav-item label="Home" /></bottom-nav>
            <native:bottom-bar><row><text>Composer</text></row></native:bottom-bar>
        </column>
        BLADE;

    expect($violationsFor('4.2.0', $stack('android:safe-area-bottom')))->toBe(0)
        ->and($violationsFor('4.2.0', $stack('ios:safe-area-bottom')))->toBe(1)
        ->and($violationsFor('4.2.0', $stack('safe-area-bottom')))->toBe(1)
        ->and($violationsFor('4.2.0', $stack('android:safe-area')))->toBe(1)
        ->and($violationsFor('4.2.0', $tabs))->toBe(1)
        ->and($violationsFor('4.3.0', $stack('android:safe-area-bottom')))->toBe(1);
});

it('reports attributes no compiler or registered hydrator consumes', function (): void {
    (new RuleTester)->run(new UnknownAttributeRule, [
        'valid' => [
            '<column class="p-4" paddingTop="8" minWidth="80" a11y-label="Card"><text :maxLines="2" color="#fff">x</text></column>',
            '<column><scroll-view axis="both"><text>x</text></scroll-view></column>',
            '<column><toggle whatever="plugin elements stand down" /></column>',
            '<column @press="save" native:model="q"><text>x</text></column>',
            '<column native:key="screen" native:poll.2s><text>x</text></column>',
        ],
        'invalid' => [
            ['code' => '<column padding-top="8"><text>x</text></column>', 'errors' => [['hasFix' => true]]],
            ['code' => '<column min-width="80"><text>x</text></column>', 'errors' => [['hasFix' => true]]],
            ['code' => '<column data-testid="card"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column native:Key="screen"><text>x</text></column>', 'errors' => [['hasFix' => true]]],
            ['code' => '<column native:kye="screen"><text>x</text></column>', 'errors' => 1],
            ['code' => '<column value="ignored" sync-mode="debounce" debounce-ms="150"><text>x</text></column>', 'errors' => 3],
            ['code' => '<column _pres="save" _change="save" _event-saved="save"><text>x</text></column>', 'errors' => 3],
            ['code' => '<column><image src="/a.png" tint-color="#fff" /></column>', 'errors' => [['hasFix' => true]]],
            ['code' => '<column @if($bad) data-testid="card" @else paddingTop="8" @endif><text>x</text></column>', 'errors' => 1],
        ],
    ]);
});
