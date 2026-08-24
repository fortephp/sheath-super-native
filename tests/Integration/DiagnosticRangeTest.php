<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Tests\Integration;

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Linter;
use Forte\Sheath\NativePhp\Rules\Guidelines\StyleAttributeRule;
use Forte\Sheath\Rules\RuleRegistry;

it('clips native element diagnostics to the opening tag', function (): void {
    $rule = new StyleAttributeRule;
    $registry = new RuleRegistry;
    $registry->register($rule);
    $config = Config::make()->setRule($rule->getId(), 'warning');
    $source = "<column style=\"gap: 8px\">\n    <text>child</text>\n</column>";

    $result = new Linter($registry)->lint(
        $source,
        'resources/views/native/range.blade.php',
        $config,
    );

    expect($result->violations)->toHaveCount(1)
        ->and($result->violations[0]->start->offset)->toBe(0)
        ->and($result->violations[0]->end->offset)->toBe(strpos($source, '>') + 1)
        ->and($result->violations[0]->end->offset)->toBeLessThan(strlen($source));
});
