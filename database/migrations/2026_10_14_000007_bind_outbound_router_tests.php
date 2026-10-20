<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {public function up():void{Schema::table('port_opener_node_policies',fn(Blueprint $t)=>$t->json('test_request_configuration')->nullable());}public function down():void{Schema::table('port_opener_node_policies',fn(Blueprint $t)=>$t->dropColumn('test_request_configuration'));}};
