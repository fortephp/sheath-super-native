<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Tests\Integration;

use Forte\Ast\Document\Document;
use Forte\Parser\ParserOptions;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Contracts\Rule;
use Forte\Sheath\Linter;
use Forte\Sheath\NativePhp\Rules\Elements\DiscardedMarkupRule;
use Forte\Sheath\NativePhp\Rules\Elements\InvalidEnumValueRule;
use Forte\Sheath\NativePhp\Rules\Elements\UnknownAttributeRule;
use Forte\Sheath\NativePhp\Rules\Elements\UnknownElementRule;
use Forte\Sheath\NativePhp\Rules\Guidelines\StyleAttributeRule;
use Forte\Sheath\NativePhp\Rules\Interaction\CallbackRule;
use Forte\Sheath\NativePhp\Rules\Interaction\KeyHygieneRule;
use Forte\Sheath\NativePhp\Rules\Interaction\ModelModifierRule;
use Forte\Sheath\NativePhp\Rules\Interaction\NavigateTransitionRule;
use Forte\Sheath\NativePhp\Rules\Styling\DeadClassRule;
use Forte\Sheath\NativePhp\Support\NativeSyntaxDocument;
use Forte\Sheath\NativePhp\Support\SpacedNativeTagExtension;
use Forte\Sheath\Results\Violation;
use Forte\Sheath\Rules\RuleRegistry;
use LogicException;

/** @return list<Violation> */
function lintNativeSyntax(string $source, Rule $rule): array
{
    $registry = new RuleRegistry;
    $registry->register($rule);
    $config = Config::make()->setRule($rule->getId(), 'error');

    return array_values(new Linter($registry)->lint(
        $source,
        'resources/views/native/syntax.blade.php',
        $config,
    )->violations);
}

it('preserves authored case when checking prefixed native tags', function (): void {
    $source = '<column><native:Column><text>x</text></native:Column></column>';
    $violations = lintNativeSyntax($source, new UnknownElementRule);
    $start = strpos($source, '<native:Column>');
    if ($start === false) {
        throw new LogicException('Fixture opening tag was not found.');
    }

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->start->offset)->toBe($start)
        ->and($violations[0]->end->offset)->toBe($start + strlen('<native:Column>'));
});

it('detects compiler-accepted whitespace native prefixes with exact opening-tag ranges', function (string $source, string $opening): void {
    $violations = lintNativeSyntax($source, new UnknownElementRule);
    $start = strpos($source, $opening);
    if ($start === false) {
        throw new LogicException('Fixture opening tag was not found.');
    }

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->start->offset)->toBe($start)
        ->and($violations[0]->end->offset)->toBe($start + strlen($opening));
})->with([
    ['<column>< native:columm/></column>', '< native:columm/>'],
    ['<column><native : columm/></column>', '<native : columm/>'],
]);

it('accepts known elements with compiler-accepted whitespace native prefixes', function (string $source): void {
    expect(lintNativeSyntax($source, new UnknownElementRule))->toBe([]);
})->with([
    '<column>< native:column/></column>',
    '<column><native : column/></column>',
]);

it('reports compiler-visible prefixed tags that raw-text parsing does not expose as elements', function (string $source): void {
    $opening = '<native:fixture-unknown />';
    $start = strpos($source, $opening);
    if ($start === false) {
        throw new LogicException('Fixture native tag was not found.');
    }

    $violations = lintNativeSyntax($source, new UnknownElementRule);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->start->offset)->toBe($start)
        ->and($violations[0]->end->offset)->toBe($start + strlen($opening));
})->with([
    'HTML comment' => ['<column><!-- <native:fixture-unknown /> --></column>'],
    'script string' => ['<column><script>const sample = "<native:fixture-unknown />";</script></column>'],
    'style text' => ['<column><style>/* <native:fixture-unknown /> */</style></column>'],
]);

it('ignores prefixed tags hidden from the precompiler by Blade', function (string $source): void {
    expect(lintNativeSyntax($source, new UnknownElementRule))->toBe([]);
})->with([
    'Blade comment' => ['<column>{{-- <native:fixture-unknown> --}}</column>'],
    'verbatim block' => ['<column>@verbatim <native:fixture-unknown> @endverbatim</column>'],
    'PHP block' => ['<column>@php $sample = "<native:fixture-unknown>"; @endphp</column>'],
]);

it('runs ordinary element and attribute rules on compiler-accepted whitespace tags', function (Rule $rule, string $attribute): void {
    expect(lintNativeSyntax("<native : column {$attribute}/>", $rule))->toHaveCount(1)
        ->and(lintNativeSyntax("< native:column {$attribute}/>", $rule))->toHaveCount(1);
})->with([
    [new UnknownAttributeRule, 'data-testid="probe"'],
    [new DeadClassRule, 'class="grid"'],
    [new StyleAttributeRule, 'style="color:red"'],
    [new InvalidEnumValueRule, 'alignItems="bogus"'],
]);

it('reports directive separators that the NativePHP precompiler cannot consume', function (Rule $rule, string $source): void {
    expect(lintNativeSyntax($source, $rule))->toHaveCount(1);
})->with([
    'callback' => [new CallbackRule, '<column><button @press = "increment">+1</button></column>'],
    'model' => [new ModelModifierRule, '<column><outlined-text-input native:model = "query"/></column>'],
    'key' => [new KeyHygieneRule, '<column native:key = "screen"/>'],
    'navigate' => [new NavigateTransitionRule, '<column><button @navigate = "home">Go</button></column>'],
    'poll fallback' => [new UnknownAttributeRule, '<column native:poll = "1s"/>'],
]);

it('reports unquoted assignments that the NativePHP precompiler drops or miscompiles', function (Rule $rule, string $source): void {
    expect(lintNativeSyntax($source, $rule))->toHaveCount(1);
})->with([
    'ordinary value' => [new UnknownAttributeRule, '<image src=logo />'],
    'slash value' => [new UnknownAttributeRule, '<image src=/logo.png />'],
    'bound value' => [new UnknownAttributeRule, '<slider :value=10 />'],
    'callback' => [new CallbackRule, '<button @press=increment>+1</button>'],
    'model' => [new ModelModifierRule, '<outlined-text-input native:model=query />'],
    'key' => [new KeyHygieneRule, '<column native:key=screen />'],
    'navigate' => [new NavigateTransitionRule, '<button @navigate=home>Go</button>'],
    'poll fallback' => [new UnknownAttributeRule, '<column native:poll=1s />'],
]);

it('fails closed for every incomplete prefixed native tag tail', function (string $source): void {
    expect(lintNativeSyntax($source, new UnknownElementRule))->toHaveCount(1);
})->with([
    '<native:column',
    '< native :',
    '</native:column',
    '<column></native:column',
    '<column',
    '</column',
    '<column></row',
]);

it('reports native collector stack underflow and unclosed openings', function (string $source): void {
    expect(lintNativeSyntax($source, new UnknownElementRule))->toHaveCount(1);
})->with([
    'bare opening' => ['<column>'],
    'prefixed opening' => ['<native : column>'],
    'bare closing' => ['</column>'],
    'extra closing' => ['<column></column></column>'],
]);

it('balances closing tags the same way as the compiler regardless of spelling or name', function (): void {
    expect(lintNativeSyntax('<column></native:row>', new UnknownElementRule))->toBe([]);
    expect(lintNativeSyntax('< native : column></native:column>', new UnknownElementRule))->toBe([]);
});

it('parses spaced native prefixes in the initial provider-configured document', function (): void {
    /** @var ParserOptions $options */
    $options = app(ParserOptions::class);
    $source = '< native : column><native: text class="p-4">Hi</native : text></ native:column>';
    $document = Document::parse($source, $options)->setFilePath('resources/views/native/probe.blade.php');
    $ruleDocument = NativeSyntaxDocument::from($document, $options);

    expect(SpacedNativeTagExtension::isConfigured($options))->toBeTrue()
        ->and($ruleDocument)->toBe($document)
        ->and($document->getElements()->map(
            static fn ($element): string => $element->tagNameText(),
        )->values()->all())->toBe(['column', 'text']);
});

it('preserves attributes after ordinary closing tags and script elements', function (): void {
    /** @var ParserOptions $options */
    $options = app(ParserOptions::class);
    $source = '</div><script></script>< native : column id="one" class="p-4" />';
    $document = Document::parse($source, $options);
    $column = $document->getElements()->last();

    expect($column?->tagNameText())->toBe('column')
        ->and($column?->attributes()->get('id')?->valueText())->toBe('one')
        ->and($column?->attributes()->get('class')?->valueText())->toBe('p-4');
});

it('parses spaced tags throughout a large native view', function (): void {
    /** @var ParserOptions $options */
    $options = app(ParserOptions::class);
    $count = 4096;
    $document = Document::parse(
        str_repeat('< native : column class="p-4" />', $count),
        $options,
    );

    expect(NativeSyntaxDocument::from($document, $options))->toBe($document)
        ->and($document->getElements())->toHaveCount($count);
});

it('does not treat ordinary short-form HTML as native syntax outside a native view', function (): void {
    $registry = new RuleRegistry;
    $rule = new UnknownElementRule;
    $registry->register($rule);

    $result = new Linter($registry)->lint(
        '<button>',
        'resources/views/web.blade.php',
        Config::make()->setRule($rule->getId(), 'error'),
    );

    expect($result->violations)->toBe([]);
});

it('normalizes native syntax after a length-changing component semantic mapping', function (): void {
    $registry = new RuleRegistry;
    $rule = new DeadClassRule;
    $registry->register($rule);
    $source = '<x-native-button class="grid" />';

    $result = new Linter($registry)->lint(
        $source,
        'resources/views/native/mapped.blade.php',
        Config::make([
            'componentMappings' => ['x-native-button' => 'button'],
            'rules' => [$rule->getId() => 'error'],
        ]),
    );

    expect($result->violations)->toHaveCount(1)
        ->and($result->violations[0]->start->offset)->toBe(0)
        ->and($result->violations[0]->end->offset)->toBe(strlen($source));
});

it('treats mixed-case bare native-looking tags as discarded markup', function (): void {
    $source = '<Column><Text>x</Text></Column>';
    $violations = lintNativeSyntax($source, new DiscardedMarkupRule);
    $textEnd = strpos($source, '>x');
    if ($textEnd === false) {
        throw new LogicException('Fixture text element was not found.');
    }

    expect($violations)->toHaveCount(2)
        ->and($violations[0]->end->offset)->toBe(strlen('<Column>'))
        ->and($violations[1]->end->offset)->toBe($textEnd + 1);
});
