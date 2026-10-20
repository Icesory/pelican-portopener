<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('port_opener_allocation_protocols', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('allocation_id')->unique();
            $table->unsignedInteger('server_id')->index();
            $table->unsignedInteger('node_id');
            $table->string('ip', 45);
            $table->unsignedInteger('port');
            $table->json('protocols');
            $table->timestamps();
        });
        Schema::table('port_opener_node_policies', fn (Blueprint $table) => $table->string('pending_mode', 16)->nullable());
        app(\PortOpener\Services\AllocationProtocols::class)->migrateEggRules();
    }
    public function down(): void
    {
        throw new RuntimeException('Preserve per-allocation approvals and clean up managed mappings before reverting this migration.');
    }
};
