<?php
namespace PortOpener;
use Filament\Contracts\Plugin;
use Filament\Panel;
class PortOpenerPlugin implements Plugin
{
    public function getId(): string { return 'port-opener'; }
    public function register(Panel $panel): void
    {
        if ($panel->getId() === 'admin') $panel->pages([
            \PortOpener\Filament\Admin\Pages\PortOpenerHub::class,
            \PortOpener\Filament\Admin\Pages\PortOpenerStatus::class,
        ]);
        if ($panel->getId() === 'admin') {
            \App\Filament\Admin\Resources\Servers\Pages\CreateServer::registerCustomSteps(
                \App\Enums\StepPosition::After,
                \Filament\Schemas\Components\Wizard\Step::make('PortOpener')->icon('tabler-router')
                    ->visible(fn () => auth()->user()?->isRootAdmin() ?? false)
                    ->schema(function (\Filament\Schemas\Components\Utilities\Get $get) {
                        $ids = collect([$get('allocation_id'), $get('allocation_additional') ?? []])->flatten()->filter()->unique()->all();
                        $fields = [\Filament\Schemas\Components\Text::make('protocol-help')->content('Choose a forwarding protocol for each allocation. Public access starts Off and can be enabled in this server’s admin PortOpener tab.')];
                        foreach (\App\Models\Allocation::whereIn('id', $ids)->where('node_id', $get('node_id'))->get() as $allocation) {
                            $fields[] = \Filament\Forms\Components\Select::make('port_opener_protocols.'.$allocation->id)->label($allocation->address)
                                ->options(\PortOpener\Services\AllocationProtocols::OPTIONS)->default('none')->required()->in(array_keys(\PortOpener\Services\AllocationProtocols::OPTIONS))->native(false);
                        }
                        return $fields;
                    }),
            );
            \App\Filament\Admin\Resources\Servers\Pages\EditServer::registerCustomTabs(
                \App\Enums\TabPosition::After,
                \Filament\Schemas\Components\Tabs\Tab::make('port-opener')->label('PortOpener')->icon('tabler-router')
                    ->visible(fn () => auth()->user()?->isRootAdmin() ?? false)->schema([
                        \Filament\Schemas\Components\Livewire::make(
                            \PortOpener\Filament\Admin\Components\PortOpenerAllocations::class,
                            fn (\App\Models\Server $record) => ['ownerRecord' => $record, 'pageClass' => \App\Filament\Admin\Resources\Servers\Pages\EditServer::class],
                        )->columnSpanFull(),
                    ]),
            );
        }
        if ($panel->getId() === 'server') {
            // Keep old bookmarks as redirects; Network owns the controls now.
            $panel->pages([\PortOpener\Filament\Server\Pages\PortExposure::class]);
            $panel->widgets([\PortOpener\Filament\Server\Widgets\PortExposureWidget::class]);
            \App\Filament\Server\Resources\Allocations\Pages\ListAllocations::registerCustomHeaderWidgets(
                \App\Enums\HeaderWidgetPosition::After,
                \PortOpener\Filament\Server\Widgets\PortExposureWidget::class,
            );
        }
    }
    public function boot(Panel $panel): void {}
}
