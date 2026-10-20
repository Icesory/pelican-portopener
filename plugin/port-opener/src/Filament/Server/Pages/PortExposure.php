<?php
namespace PortOpener\Filament\Server\Pages;
use Filament\Pages\Page;
use PortOpener\Concerns\InteractsWithPortExposure;
class PortExposure extends Page
{
    use InteractsWithPortExposure { mount as initializeExposure; }
    protected string $view = 'port-opener::server.port-exposure';
    protected static ?string $title = 'PortOpenerService';
    protected static bool $shouldRegisterNavigation = false;
    public function mount(): void
    {
        $this->initializeExposure();
        $this->redirect(\App\Filament\Server\Resources\Allocations\AllocationResource::getUrl('index', panel: 'server'));
    }
}
