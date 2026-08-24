<?php

namespace App\NativeComponents\Layouts;

use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\UI\Builders\Drawer;

class FixtureDrawerLayout extends NativeLayout
{
    public function drawer(NativeComponent $screen)
    {
        $drawer = Drawer::make(view('native.fixture-menu'))->width(300);

        $mode = method_exists($screen, 'drawerMode') ? $screen->drawerMode() : 'modal';

        return $mode === 'reveal' ? $drawer->reveal() : $drawer->modal();
    }
}
