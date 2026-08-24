<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Support;

final class Suggestions
{
    /**
     * @param  array<string>  $candidates
     */
    public static function closest(string $input, array $candidates): ?string
    {
        $input = strtolower($input);
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($candidates as $candidate) {
            $distance = levenshtein($input, strtolower($candidate));

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        if ($best === null) {
            return null;
        }

        $threshold = max(1, intdiv(strlen($input), 4));

        return $bestDistance <= min(2, $threshold + 1) ? $best : null;
    }

    /**
     * @param  array<string>  $candidates
     */
    public static function hint(string $input, array $candidates): string
    {
        return self::hintFor(self::closest($input, $candidates));
    }

    public static function hintFor(?string $closest): string
    {
        return $closest === null ? '' : " Did you mean '{$closest}'?";
    }
}
