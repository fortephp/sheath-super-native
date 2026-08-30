<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Tests\Integration;

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Linter;
use Forte\Sheath\NativePhp\Rules\Elements\DiscardedMarkupRule;
use Forte\Sheath\NativePhp\Rules\Elements\InvalidEnumValueRule;
use Forte\Sheath\NativePhp\Rules\Elements\UnknownAttributeRule;
use Forte\Sheath\NativePhp\Rules\Elements\UnknownElementRule;
use Forte\Sheath\NativePhp\Rules\Guidelines\StyleAttributeRule;
use Forte\Sheath\NativePhp\Rules\Interaction\CallbackRule;
use Forte\Sheath\NativePhp\Rules\Interaction\KeyHygieneRule;
use Forte\Sheath\NativePhp\Rules\Interaction\UnsupportedEventRule;
use Forte\Sheath\NativePhp\Rules\Styling\DarkVariantTargetRule;
use Forte\Sheath\NativePhp\Rules\Styling\DeadClassRule;
use Forte\Sheath\NativePhp\Rules\Styling\MisrenderClassRule;
use Forte\Sheath\Rules\RuleRegistry;

it('lets unknown-attribute exclusively own dropped alignment spellings', function (string $attribute): void {
    $enum = new InvalidEnumValueRule;
    $unknown = new UnknownAttributeRule;
    $registry = new RuleRegistry;
    $registry->register($enum);
    $registry->register($unknown);
    $config = Config::make()
        ->setRule($enum->getId(), 'error')
        ->setRule($unknown->getId(), 'warning');

    $result = new Linter($registry)->lint(
        "<column {$attribute}=\"definitely-invalid\"><text>x</text></column>",
        'resources/views/native/ownership.blade.php',
        $config,
    );

    expect($result->violations)->toHaveCount(1)
        ->and($result->violations[0]->ruleId)->toBe('native-unknown-attribute');
})->with(['align-items', 'alignitems', 'AlignItems']);

it('keeps style spellings on the purpose-built style rule', function (): void {
    $style = new StyleAttributeRule;
    $unknown = new UnknownAttributeRule;
    $registry = new RuleRegistry;
    $registry->register($style);
    $registry->register($unknown);
    $config = Config::make()
        ->setRule($style->getId(), 'warning')
        ->setRule($unknown->getId(), 'warning');

    $exact = new Linter($registry)->lint(
        '<column style="color: red"><text>x</text></column>',
        'resources/views/native/ownership.blade.php',
        $config,
    );
    $cased = new Linter($registry)->lint(
        '<column Style="color: red"><text>x</text></column>',
        'resources/views/native/ownership.blade.php',
        $config,
    );

    expect($exact->violations)->toHaveCount(1)
        ->and($exact->violations[0]->ruleId)->toBe('native-no-style-attribute')
        ->and($cased->violations)->toHaveCount(1)
        ->and($cased->violations[0]->ruleId)->toBe('native-no-style-attribute');
});

it('does not validate a callback for an event the element cannot dispatch', function (): void {
    $callback = new CallbackRule;
    $callback->setOptions(['componentPaths' => [__DIR__.'/../Fixtures/NativeComponents']]);
    $unsupported = new UnsupportedEventRule;
    $registry = new RuleRegistry;
    $registry->register($callback);
    $registry->register($unsupported);
    $config = Config::make()
        ->setRule($callback->getId(), 'error')
        ->setRule($unsupported->getId(), 'warning');

    $result = new Linter($registry)->lint(
        '<column @change="incremnt"><text>x</text></column>',
        'resources/views/native/counter-demo.blade.php',
        $config,
    );

    expect($result->violations)->toHaveCount(1)
        ->and($result->violations[0]->ruleId)->toBe('native-unsupported-event');
});

it('lets unknown-attribute own invalid native:key casing', function (): void {
    $key = new KeyHygieneRule;
    $unknown = new UnknownAttributeRule;
    $registry = new RuleRegistry;
    $registry->register($key);
    $registry->register($unknown);
    $config = Config::make()
        ->setRule($key->getId(), 'warning')
        ->setRule($unknown->getId(), 'warning');

    $result = new Linter($registry)->lint(
        '<column><row Native-Key="dup" /><row Native-Key="dup" /></column>',
        'resources/views/native/ownership.blade.php',
        $config,
    );

    expect($result->violations)->toHaveCount(2)
        ->and(array_unique(array_column($result->violations, 'ruleId')))->toBe(['native-unknown-attribute']);
});

it('lets key hygiene exclusively own child-component key syntax on native elements', function (): void {
    $key = new KeyHygieneRule;
    $unknown = new UnknownAttributeRule;
    $registry = new RuleRegistry;
    $registry->register($key);
    $registry->register($unknown);
    $config = Config::make()
        ->setRule($key->getId(), 'warning')
        ->setRule($unknown->getId(), 'warning');

    $result = new Linter($registry)->lint(
        '<column><row key="item" /></column>',
        'resources/views/native/ownership.blade.php',
        $config,
    );

    $fallback = new Linter($registry)->lint(
        '<column><row key="item" /></column>',
        'resources/views/native/ownership.blade.php',
        Config::make()
            ->setRule($key->getId(), 'off')
            ->setRule($unknown->getId(), 'warning'),
    );

    expect($result->violations)->toHaveCount(1)
        ->and($result->violations[0]->ruleId)->toBe('native-key-hygiene')
        ->and($fallback->violations)->toHaveCount(1)
        ->and($fallback->violations[0]->ruleId)->toBe('native-unknown-attribute');
});

it('does not run class diagnostics on elements the renderer discards or rejects', function (string $source, string $owner): void {
    $dead = new DeadClassRule;
    $unknown = new UnknownElementRule;
    $discarded = new DiscardedMarkupRule;
    $registry = new RuleRegistry;
    $registry->register($dead);
    $registry->register($unknown);
    $registry->register($discarded);
    $config = Config::make()
        ->setRule($dead->getId(), 'warning')
        ->setRule($unknown->getId(), 'error')
        ->setRule($discarded->getId(), 'warning');

    $result = new Linter($registry)->lint(
        $source,
        'resources/views/native/ownership.blade.php',
        $config,
    );

    expect($result->violations)->toHaveCount(1)
        ->and($result->violations[0]->ruleId)->toBe($owner);
})->with([
    ['<column><native:made-up class="grid-cols-3" /></column>', 'native-unknown-element'],
    ['<column><made-up class="grid-cols-3" /></column>', 'native-discarded-markup'],
]);

it('assigns reverse flex diagnostics to exactly one styling rule', function (string $class, string $owner): void {
    $dead = new DeadClassRule;
    $dark = new DarkVariantTargetRule;
    $misrender = new MisrenderClassRule;
    $registry = new RuleRegistry;
    $registry->register($dead);
    $registry->register($dark);
    $registry->register($misrender);
    $config = Config::make()
        ->setRule($dead->getId(), 'warning')
        ->setRule($dark->getId(), 'warning')
        ->setRule($misrender->getId(), 'error');

    $result = new Linter($registry)->lint(
        "<column class=\"{$class}\"><text>x</text></column>",
        'resources/views/native/ownership.blade.php',
        $config,
    );

    expect($result->violations)->toHaveCount(1)
        ->and($result->violations[0]->ruleId)->toBe($owner);
})->with([
    ['flex-row-reverse', 'native-misrender-class'],
    ['ios:flex-col-reverse', 'native-misrender-class'],
    ['dark:flex-row-reverse', 'native-dark-variant-target'],
    ['hover:flex-row-reverse', 'native-dead-class'],
]);
