<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('port_opener_node_policies', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('node_id')->unique(); $t->boolean('enabled')->default(false);
            $t->string('internal_address', 45); $t->unsignedInteger('min_port')->default(1024); $t->unsignedInteger('max_port')->default(65535); $t->timestamps();
        });
        Schema::create('port_opener_egg_rules', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('egg_id')->index(); $t->string('name'); $t->string('source'); $t->string('variable_name')->nullable();
            $t->json('protocols'); $t->boolean('public')->default(false); $t->timestamps();
        });
        Schema::create('port_opener_exposures', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('server_id')->unique(); $t->boolean('enabled')->default(false); $t->timestamps();
        });
        Schema::create('port_opener_sync_states', function (Blueprint $t) {
            $t->id(); $t->uuid('installation_id')->unique(); $t->unsignedBigInteger('generation')->default(0); $t->string('desired_hash', 64)->nullable();
            $t->json('last_status')->nullable(); $t->text('last_error')->nullable(); $t->timestamp('synced_at')->nullable(); $t->timestamps();
        });
    }
    public function down(): void {
        // Preserve generation/ownership unless the most recent complete empty snapshot was acknowledged.
        $active = \Illuminate\Support\Facades\DB::table('port_opener_exposures')->where('enabled',true)->exists();
        $states = \Illuminate\Support\Facades\DB::table('port_opener_sync_states')->get();
        foreach ($states as $state) {
            $status = json_decode($state->last_status ?? '{}',true);
            $clean = !$state->last_error && ($status['router_reachable'] ?? false)
                && (int)($status['generation'] ?? -1) === (int)$state->generation
                && is_array($status['mappings'] ?? null);
            foreach ($status['mappings'] ?? [] as $mapping) $clean = $clean && ($mapping['state'] ?? null) === 'removed';
            $emptyHash = hash('sha256',json_encode(['node_id'=>(int)config('port-opener.node_id'),'mappings'=>[]],JSON_THROW_ON_ERROR));
            if (!$clean || $state->desired_hash !== $emptyHash) throw new RuntimeException('Run port-opener:sync --cleanup and verify removal before uninstalling.');
        }
        if ($active) throw new RuntimeException('Disable exposures and run port-opener:sync --cleanup before uninstalling.');
        foreach (['port_opener_exposures','port_opener_egg_rules','port_opener_node_policies','port_opener_sync_states'] as $table) Schema::dropIfExists($table);
    }
};
