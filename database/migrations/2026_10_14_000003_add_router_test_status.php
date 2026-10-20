<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('port_opener_node_policies',function(Blueprint $t) { $t->json('last_router_test')->nullable(); $t->timestamp('router_tested_at')->nullable(); }); }
    public function down(): void { Schema::table('port_opener_node_policies',fn(Blueprint $t)=>$t->dropColumn(['last_router_test','router_tested_at'])); }
};
