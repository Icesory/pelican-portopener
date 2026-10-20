<?php

namespace PortOpener\Filament\Admin\Components;

use App\Filament\Admin\Resources\Servers\RelationManagers\AllocationsRelationManager;
use App\Models\{Allocation, Server};
use Filament\Tables\Columns\{SelectColumn, TextColumn};
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use PortOpener\Services\{AllocationProtocols, StatusOverviewService};

/** Extend Pelican's real relation manager so its allocation actions retain their semantics. */
class PortOpenerAllocations extends AllocationsRelationManager
{
    protected string $view = 'port-opener::admin.server-portopener';
    private ?\Illuminate\Support\Collection $portRows = null;

    private function authorizeAdministrator(): void
    {
        abort_unless(auth()->user()?->isRootAdmin() && $this->getOwnerRecord() instanceof Server, 403);
    }

    public function mount(): void { $this->authorizeAdministrator(); parent::mount(); }
    public function hydrate(): void { $this->authorizeAdministrator(); }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->isRootAdmin() ?? false;
    }

    public function table(Table $table): Table
    {
        $this->authorizeAdministrator();
        $table = parent::table($table);
        $columns = array_values($table->getColumns());
        foreach ($columns as $column) if (in_array($column->getName(), ['ip_alias', 'notes'], true)) $column->toggleable();
        $protocol = SelectColumn::make('port_opener_protocol')
                ->label('Forwarding protocol')
                ->options(AllocationProtocols::OPTIONS)
                ->rules(['required', 'in:none,tcp,udp,both'])->selectablePlaceholder(false)
                ->getStateUsing(fn (Allocation $record) => app(AllocationProtocols::class)->choice($record))
                ->updateStateUsing(function (Allocation $record, string $state) {
                    $this->authorizeAdministrator();
                    app(AllocationProtocols::class)->set($this->getOwnerRecord(), auth()->user(), $record->id, $state);
                    return $state;
                });
        array_splice($columns, 2, 0, [$protocol]);
        return $table->columns([
            ...$columns,
            TextColumn::make('forwarding_state')->label('Forwarding')->badge()
                ->getStateUsing(function (Allocation $record) {
                    $rows = ($this->portRows ??= collect(app(StatusOverviewService::class)->overview()['ports']))
                        ->where('server_id', $this->getOwnerRecord()->id)->where('allocation_id', $record->id);
                    if ($rows->contains(fn ($row) => !empty($row['simulation']))) return 'Simulation';
                    return $rows->map(fn ($row) => ucfirst($row['state']))->unique()->implode(' / ') ?: 'Not managed';
                }),
        ]);
    }
}
