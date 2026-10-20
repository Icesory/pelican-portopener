<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('port_opener_egg_rules',fn(Blueprint $t)=>$t->string('allocation_alias')->nullable()); }
    public function down(): void { Schema::table('port_opener_egg_rules',fn(Blueprint $t)=>$t->dropColumn('allocation_alias')); }
};
