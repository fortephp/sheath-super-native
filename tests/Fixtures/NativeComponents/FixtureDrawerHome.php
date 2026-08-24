<?php

namespace App\NativeComponents;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;

class FixtureDrawerHome extends NativeComponent
{
    public function render(): Element|View
    {
        return view('native.fixture-home');
    }

    public function drawerMode(): string
    {
        return 'modal';
    }

    public function openSettings(): void {}
}
