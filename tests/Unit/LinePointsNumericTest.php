<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Tests\Unit;

use Forte\Sheath\NativePhp\Rules\Elements\LinePointsRule;
use Forte\Sheath\Testing\RuleTester;

it('matches the compiler float semantics for line coordinates', function (): void {
    (new RuleTester)->run(new LinePointsRule, [
        'valid' => [
            '<column><canvas><line from="+1,2" to="30,40" /></canvas></column>',
            '<column><canvas><line from="1e2,5" to="30,40" /></canvas></column>',
            '<column><canvas><line from=".5,2" to="30,40" /></canvas></column>',
            '<column><canvas><line from="1, 2" to=" 30 , 40 " /></canvas></column>',
            '<column><canvas><line from="-3.5,7" to="1.5e-3,2" /></canvas></column>',
            '<column><canvas><line from="1e308,-1e308" to="30,40" /></canvas></column>',
        ],
        'invalid' => [
            ['code' => '<column><canvas><line from="1e999,2" to="30,40" /></canvas></column>', 'errors' => 1],
            ['code' => '<column><canvas><line from="10,20" to="-1e999,5" /></canvas></column>', 'errors' => 1],
            ['code' => '<column><canvas><line from="a,b" to="30,40" /></canvas></column>', 'errors' => 1],
            ['code' => '<column><canvas><line from="10,20" to="a,5" /></canvas></column>', 'errors' => 1],
            ['code' => '<column><canvas><line from="10px,5" to="30,40" /></canvas></column>', 'errors' => 1],
            ['code' => '<column><canvas><line from="1,2,3" to="30,40" /></canvas></column>', 'errors' => 1],
            ['code' => '<column><canvas><line from="10,20" to="5" /></canvas></column>', 'errors' => 1],
            ['code' => '<column><canvas><line from to="3,4" /></canvas></column>', 'errors' => 1],
        ],
    ]);
});
