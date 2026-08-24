<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Support\Theme;

use BackedEnum;
use Forte\Sheath\NativePhp\Support\TailwindOracle;
use Illuminate\Contracts\Config\Repository;
use Throwable;
use UnitEnum;

final readonly class ThemeTokenSource
{
    /** @param list<string>|null $configuredTokens */
    private function __construct(
        private ?array $configuredTokens,
        private ?bool $runtimeResolver,
    ) {}

    public static function current(): self
    {
        return new self(
            self::configuredTokens(),
            TailwindOracle::hasRuntimeThemeResolver(),
        );
    }

    public function canVerify(): bool
    {
        return $this->configuredTokens !== null || $this->runtimeResolver !== null;
    }

    /** @return list<string> */
    public function tokens(): array
    {
        return $this->configuredTokens ?? [];
    }

    public function status(string $class, string $token): ThemeTokenStatus
    {
        if (! $this->canVerify()) {
            return ThemeTokenStatus::Unverifiable;
        }

        if ($this->runtimeResolver === true) {
            $contributes = TailwindOracle::runtimeClassContributes($class);

            if ($contributes === true) {
                return ThemeTokenStatus::Resolved;
            }

            if ($contributes === false) {
                return ThemeTokenStatus::UndefinedRuntime;
            }
        }

        if (in_array($token, $this->tokens(), true)) {
            return ThemeTokenStatus::Resolved;
        }

        if ($this->configuredTokens === null && $this->runtimeResolver === false) {
            return ThemeTokenStatus::MissingSource;
        }

        return ThemeTokenStatus::UndefinedConfigured;
    }

    public static function configurationFingerprint(): mixed
    {
        return self::normalize(self::configuredTheme());
    }

    /** @return list<string>|null */
    private static function configuredTokens(): ?array
    {
        $theme = self::configuredTheme();
        if (! is_array($theme)) {
            return null;
        }

        $light = $theme['light'] ?? null;
        if (! is_array($light)) {
            return [];
        }

        return array_values(array_unique(array_map(strval(...), array_keys($light))));
    }

    private static function configuredTheme(): mixed
    {
        if (! function_exists('app')) {
            return null;
        }

        try {
            $app = app();
            if (! $app->bound('config')) {
                return null;
            }

            $repository = $app->make('config');

            return $repository instanceof Repository
                ? $repository->get('native-ui.theme')
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[(string) $key] = self::normalize($item);
            }
            ksort($normalized);

            return $normalized;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if (is_object($value)) {
            return ['object' => $value::class];
        }

        if (is_resource($value)) {
            return ['resource' => get_resource_type($value)];
        }

        return $value;
    }
}
