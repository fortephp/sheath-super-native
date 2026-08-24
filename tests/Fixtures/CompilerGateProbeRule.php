<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Tests\Fixtures;

use Forte\Ast\Document\Document;
use Forte\Sheath\NativePhp\Rules\BaseRule;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleContext;

final class CompilerGateProbeRule extends BaseRule
{
    public static ?bool $runs = null;

    public function getId(): string
    {
        return 'compiler-gate-probe';
    }

    public function getDescription(): string
    {
        return 'Records whether targetsSupportedCompiler lets rules run.';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
    }

    public function check(Document $document, RuleContext $context): void
    {
        self::$runs = $this->targetsSupportedCompiler($context);
    }
}
