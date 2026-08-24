# Sheath SuperNative

Catch mistakes in [NativePHP SuperNative](https://nativephp.com/docs/mobile/4/getting-started/introduction) Blade views before they reach a device.

This [Sheath](https://github.com/fortephp/sheath) plugin understands native elements, event and model bindings, NativePHP's Tailwind subset, layout rules, themes, and accessibility conventions. It reports problems directly in your Blade views without rendering a screen or launching an app.

## Installation

```bash
composer require --dev fortephp/sheath-super-native
```

Laravel discovers the package automatically. If your application does not have a Sheath config yet, publish it:

```bash
php artisan vendor:publish --tag=sheath-config
```

Then add the `nativephp` preset to `config/sheath.php`:

```php
'preset' => ['recommended', 'nativephp'],
```

Keep `nativephp` after the built-in presets. Later presets win, and this ordering prevents web-focused HTML rules from reporting native markup.

Run Sheath normally:

```bash
php artisan sheath:lint
```

You can also try the plugin without changing your config:

```bash
php artisan sheath:lint --preset=nativephp
```

## Usage

Sheath scans its configured view paths by default. You can also lint a directory or one file:

```bash
php artisan sheath:lint resources/views/native
php artisan sheath:lint resources/views/native/home.blade.php
```

Some spelling mistakes have safe fixes:

```bash
php artisan sheath:lint --fix
```

Use `php artisan sheath:lint --print-config` to see every active rule and severity.

## What it catches

- unknown, incomplete, or unbalanced native elements;
- discarded HTML, unsupported attributes, invalid enum values, and malformed line points;
- unsupported events, missing callbacks, invalid navigation transitions, and callback argument mismatches;
- model bindings on the wrong element or properties that are missing, private, readonly, or locked;
- missing, duplicate, constant, or position-based keys in repeated content;
- unsupported Tailwind utilities and classes that NativePHP interprets differently than written;
- incomplete borders, ignored dark variants, invalid glass modifiers, and misplaced text styles;
- invalid native layout structure, duplicate safe-area padding, undefined theme tokens, and unnamed icon controls.

Diagnostics point to the authored Blade element rather than generated compiler output, so the problem is easier to find and fix.

The plugin reads registered SuperNative elements, components, attributes, events, and theme behavior from the installed NativePHP runtime. Plugin-provided features are recognized automatically, while dynamic behavior the linter cannot verify is left alone instead of guessed at.

## Configuration

You can override individual rules in `config/sheath.php`:

```php
'rules' => [
    'native-no-emoji' => 'off',
    'native-prefer-theme-tokens' => 'off',
    'native-key-hygiene' => 'error',
],
```

Native rules target `resources/views/native` by default and also recognize views containing unmistakable SuperNative tags. If your native views live elsewhere, pass a `nativeViewPaths` option to the native rules you enable. Configuring explicit paths switches detection to path-only, which is useful when web components share names with native elements.

## Requirements

- PHP 8.4 or newer
- Laravel 12 or 13
- NativePHP Mobile 4.x (SuperNative)
- Sheath 1.x

## License

MIT. See [license.md](license.md).
