<?php

namespace App\NativeComponents;

use Illuminate\View\View;
use Native\Mobile\Attributes\Locked;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;

class VisibilityScreen extends NativeComponent
{
    public string $title = '';

    public readonly string $immutable;

    protected string $draft = '';

    #[Locked]
    public int $accountId = 0;

    public function render(): Element|View
    {
        return view('native.fixture-visibility');
    }
}
