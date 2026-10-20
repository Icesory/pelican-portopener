<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
public function up():void {Schema::table('port_opener_exposures',function(Blueprint $t){$t->string('mode',32)->default('always');$t->timestamp('offline_since')->nullable();$t->string('last_runtime_state',32)->nullable();$t->string('last_confirmed_runtime_state',32)->nullable();$t->timestamp('last_runtime_checked_at')->nullable();});}
public function down():void {Schema::table('port_opener_exposures',fn(Blueprint $t)=>$t->dropColumn(['mode','offline_since','last_runtime_state','last_confirmed_runtime_state','last_runtime_checked_at']));}
};
