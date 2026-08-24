<?php

declare(strict_types=1);

namespace App\NativeComponents;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class MisleadingViewReference extends NativeComponent
{
    public string $title = 'Real owner';

    public function save(): void {}

    public function preview(): View
    {
        return view('native.not-owned');
    }

    public function render(): View
    {
        return view('native.actually-owned');
    }
}
