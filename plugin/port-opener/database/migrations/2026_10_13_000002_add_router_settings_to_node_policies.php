<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('port_opener_node_policies', function (Blueprint $table) {
            $table->string('forwarding_type', 32)->default('upnp');
            $table->string('router_url', 2048)->nullable();
        });
    }
    public function down(): void {
        Schema::table('port_opener_node_policies', function (Blueprint $table) {
            $table->dropColumn(['forwarding_type','router_url']);
        });
    }
};
