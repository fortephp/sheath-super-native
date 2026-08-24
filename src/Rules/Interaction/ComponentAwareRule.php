<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp\Rules\Interaction;

use Forte\Sheath\NativePhp\Rules\BaseRule;
use Forte\Sheath\NativePhp\Support\ComponentViewMap;
use Override;

abstract class ComponentAwareRule extends BaseRule
{
    protected array $options = [
        'nativeViewPaths' => ['views/native/'],
        'componentPaths' => ['app/NativeComponents'],
    ];

    #[Override]
    public function cacheContext(array $options): array|string
    {
        $paths = $this->componentPathsFrom($options);

        return [
            'native' => parent::cacheContext($options),
            'owners' => ComponentViewMap::sourceFingerprint($paths),
        ];
    }

    #[Override]
    public function cacheContextGroup(array $options): string
    {
        return 'nativephp:components:'.hash('xxh128', serialize($this->componentPathsFrom($options)));
    }

    /** @return list<string> */
    protected function componentPaths(): array
    {
        return $this->normalizeComponentPaths(
            (array) $this->getOption('componentPaths', $this->options['componentPaths']),
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    private function componentPathsFrom(array $options): array
    {
        return $this->normalizeComponentPaths(
            (array) ($options['componentPaths'] ?? $this->options['componentPaths']),
        );
    }

    /**
     * @param  array<array-key, mixed>  $paths
     * @return list<string>
     */
    private function normalizeComponentPaths(array $paths): array
    {
        $normalized = [];

        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                $normalized[] = $path;
            }
        }

        return $normalized;
    }
}
