<?php

namespace App\NativeComponents;

use App\NativeComponents\Concerns\HandlesSharing;
use Illuminate\View\View;
use Native\Mobile\Attributes\Locked;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;

class CounterDemo extends NativeComponent
{
    use HandlesSharing;

    public int $count = 0;

    public string $query = '';

    #[Locked]
    public int $ownerId = 0;

    public function render(): Element|View
    {
        return view('native.counter-demo');
    }

    public function increment(): void
    {
        $this->count++;
    }

    public function viewProfile(int $id): void {}

    public function moveItem(int $from, int $to): void {}

    public function oneEventArgument(string $value): void {}

    public function setQuery(string $value): void
    {
        $this->query = $value;
    }

    public function selectionChanged(string $text, int $start, int $end): void {}

    public function selectionWithContext(string $text, int $start, int $end, string $context): void {}

    private function secretReset(): void
    {
        $this->count = 0;
    }
}
