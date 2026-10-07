<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Tests\Fixtures;

use Livewire\Component;

final class HtmlCacheIsolationComponent extends Component
{
    public function render(): string
    {
        return '<div>Livewire isolation render</div>';
    }
}
