<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('port_opener_node_policies', function(Blueprint $table){
            $table->boolean('ipv6_enabled')->default(false);
            $table->string('ipv6_interface',32)->nullable();
            $table->json('ipv6_mapping_ids')->nullable();
        });
        Schema::table('port_opener_exposures', fn(Blueprint $table)=>$table->boolean('ipv6_enabled')->default(false));
    }
    public function down(): void { throw new RuntimeException('Disable IPv6 exposure and wait for finite leases to expire before removing IPv6 configuration.'); }
};
