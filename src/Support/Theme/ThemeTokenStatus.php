<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Support\Theme;

enum ThemeTokenStatus
{
    case Resolved;
    case UndefinedRuntime;
    case UndefinedConfigured;
    case MissingSource;
    case Unverifiable;
}
