<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Tests\Integration;

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Contracts\Rule;
use Forte\Sheath\Fixer;
use Forte\Sheath\Linter;
use Forte\Sheath\NativePhp\Rules\Elements\UnknownAttributeRule;
use Forte\Sheath\NativePhp\Rules\Elements\UnknownElementRule;
use Forte\Sheath\NativePhp\Rules\Interaction\NavigateTransitionRule;
use Forte\Sheath\NativePhp\Rules\Styling\UnknownThemeTokenRule;
use Forte\Sheath\Rules\RuleRegistry;

it('produces fixes that remove the originating violation', function (Rule $rule, string $source): void {
    config()->set('native-ui.theme', [
        'light' => ['surface' => '#FFFFFF'],
    ]);

    $registry = new RuleRegistry;
    $registry->register($rule);
    $linter = new Linter($registry);
    $configuration = Config::make()->setRule($rule->getId(), 'error');

    $before = $linter->lint($source, 'resources/views/native/fix-probe.blade.php', $configuration);

    expect($before->violations)->toHaveCount(1)
        ->and($before->getFixes())->toHaveCount(1);

    $fixed = (new Fixer)->applyFixes($source, $before->getFixes());
    $after = $linter->lint($fixed->content, 'resources/views/native/fix-probe.blade.php', $configuration);

    expect($fixed->appliedCount)->toBe(1)
        ->and($fixed->content)->not->toBe($source)
        ->and($after->violations)->toBeEmpty();
})->with([
    'unknown element' => [new UnknownElementRule, '<native:column><native:txet name="a" /></native:column>'],
    'unknown attribute' => [new UnknownAttributeRule, '<column padding-top="8"><text>x</text></column>'],
    'navigate transition' => [new NavigateTransitionRule, '<column @navigate.slideFromRigt="/detail"><text>x</text></column>'],
    'theme token' => [new UnknownThemeTokenRule, '<column class="bg-theme-surfase"><text>x</text></column>'],
]);
