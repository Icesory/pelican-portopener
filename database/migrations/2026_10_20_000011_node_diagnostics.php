<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('port_opener_node_policies', function (Blueprint $table) {
            $table->timestamp('debug_until')->nullable();
            $table->json('service_diagnostics')->nullable();
        });
    }
    public function down(): void {
        Schema::table('port_opener_node_policies', fn (Blueprint $table) => $table->dropColumn(['debug_until', 'service_diagnostics']));
    }
};
