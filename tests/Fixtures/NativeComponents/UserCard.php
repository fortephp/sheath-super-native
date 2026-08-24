<?php

namespace App\NativeComponents;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;

class UserCard extends NativeComponent
{
    public string $title = '';

    public function render(): Element|View
    {
        return view('native.user-card');
    }
}
