<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        // Fixtures mimic application code verbatim; modernizing them would
        // drift the tests away from what real components look like.
        __DIR__.'/tests/Fixtures',
    ])
    ->withPhpSets()
    ->withPreparedSets(deadCode: true)
    ->withTypeCoverageLevel(0)
    ->withCodeQualityLevel(0);
