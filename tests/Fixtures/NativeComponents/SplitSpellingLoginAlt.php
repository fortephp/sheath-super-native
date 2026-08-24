<?php

namespace App\NativeComponents;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;

class SplitSpellingLoginAlt extends NativeComponent
{
    public function render(): Element|View
    {
        return view('native.fixture-split');
    }
}
