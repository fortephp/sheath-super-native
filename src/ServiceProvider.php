<?php

declare(strict_types=1);

namespace Forte\Sheath\NativePhp;

use Forte\Parser\ParserOptions;
use Forte\Sheath\NativePhp\Support\SpacedNativeTagExtension;
use Forte\Sheath\SheathManager;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * @var array<string, string|array{0: string, 1: array<string, mixed>}>
     */
    private const array PRESET = [
        'native-unknown-element' => 'error',
        'native-discarded-markup' => 'warning',
        'native-dead-class' => 'warning',
        'native-misrender-class' => 'error',
        'native-dark-variant-target' => 'warning',
        'native-border-requires-pair' => 'warning',
        'native-glass-modifier' => 'warning',
        'native-invalid-enum-value' => 'error',
        'native-unknown-navigate-transition' => 'error',
        'native-unsupported-event' => 'warning',
        'native-model-on-non-input' => 'error',
        'native-no-style-attribute' => 'warning',
        'native-line-points' => 'error',
        'native-callback-exists' => 'error',
        'native-model-property' => 'error',
        'native-model-modifier' => 'error',
        'native-unknown-attribute' => 'warning',
        'native-key-hygiene' => 'warning',
        'native-structure' => 'error',
        'native-typography-target' => 'warning',
        'native-icon-a11y-label' => 'warning',
        'native-no-emoji' => 'warning',
        'native-safe-area-with-chrome' => 'warning',
        'native-prefer-theme-tokens' => 'info',
        'native-component-slot-content' => 'error',
        'native-unknown-theme-token' => 'error',

        'best-practices-no-obsolete-tags' => ['warning', ['exclude' => ['views/native/']]],
        'best-practices-button-type' => ['warning', ['exclude' => ['views/native/']]],
        'best-practices-no-inline-styles' => ['warning', ['exclude' => ['views/native/']]],
        'a11y-button-accessible-name' => ['error', ['exclude' => ['views/native/']]],
        'a11y-form-label' => ['error', ['exclude' => ['views/native/']]],
    ];

    public function boot(SheathManager $sheath, ParserOptions $parserOptions): void
    {
        SpacedNativeTagExtension::configure($parserOptions);
        $sheath->discoverRules(__DIR__.'/Rules', __NAMESPACE__.'\\Rules');
        $sheath->registerPreset('nativephp', self::PRESET);
    }
}
