<?php
namespace PortOpener\Filament\Server\Widgets;
use Filament\Widgets\Widget;
use PortOpener\Concerns\InteractsWithPortExposure;
class PortExposureWidget extends Widget implements \Filament\Tables\Contracts\HasTable, \Filament\Schemas\Contracts\HasSchemas, \Filament\Actions\Contracts\HasActions
{
    use InteractsWithPortExposure;
    use \Filament\Tables\Concerns\InteractsWithTable, \Filament\Schemas\Concerns\InteractsWithSchemas, \Filament\Actions\Concerns\InteractsWithActions;
    protected string $view = 'port-opener::server.network-port-opener';
    protected int|string|array $columnSpan = 'full';
    protected static bool $isLazy = false;
    public static function canView(): bool { return static::canAccess(); }

    public function table(\Filament\Tables\Table $table): \Filament\Tables\Table
    {
        return $table->records(function () {
            $data = $this->getViewData();
            return array_map(function ($port) use ($data) {
                $row = $data['observed']->get($port['mapping_id']);
                $v6 = $data['ipv6Observed']->get($port['mapping_id']);
                return ['display_port' => $port['display_port'], 'protocol' => $port['protocol'],
                    'ipv4' => ucfirst($row['state'] ?? (!$data['enabled'] ? 'disabled' : (in_array($data['runtimePolicyState'], ['waiting', 'unknown'], true) ? $data['runtimePolicyState'] : 'pending'))) . ($row && !$data['reportFresh'] ? ' (stale)' : ''),
                    'ipv6' => ($v6['error_code'] ?? null) === 'ipv6_verification_denied' ? 'Requested (unverified)' : ucfirst($v6['state'] ?? (!$data['ipv6Approved'] || !$data['enabled'] ? 'disabled' : 'pending'))];
            }, $data['ports']);
        })->paginated(false)->columns([
            \Filament\Tables\Columns\TextColumn::make('display_port')->label('Port'),
            \Filament\Tables\Columns\TextColumn::make('protocol')->label('Protocol'),
            \Filament\Tables\Columns\TextColumn::make('ipv4')->label('IPv4 state')->badge(),
            \Filament\Tables\Columns\TextColumn::make('ipv6')->label('IPv6 state')->badge()->visible(fn () => $this->getViewData()['ipv6NodeEnabled']),
        ])->emptyStateHeading('No ports are managed by PortOpenerService.');
    }
}
