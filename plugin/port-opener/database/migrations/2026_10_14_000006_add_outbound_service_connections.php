<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {
public function up():void {
Schema::table('port_opener_node_policies',function(Blueprint $t){$t->string('connection_mode',32)->default('legacy_local');$t->uuid('installation_id')->nullable()->unique();$t->boolean('service_enabled')->default(false);$t->boolean('service_dry_run')->default(true);$t->unsignedInteger('lease_seconds')->default(3600);$t->unsignedInteger('reconcile_seconds')->default(60);$t->timestamp('last_contact_at')->nullable();$t->json('last_report')->nullable();$t->json('desired_snapshot')->nullable();$t->unsignedBigInteger('desired_generation')->default(0);$t->string('snapshot_hash',64)->nullable();$t->text('service_error')->nullable();$t->uuid('test_request_id')->nullable();});
Schema::create('port_opener_pairings',function(Blueprint $t){$t->id();$t->unsignedBigInteger('node_id')->index();$t->string('code_hash',64)->unique();$t->timestamp('expires_at');$t->timestamp('used_at')->nullable();$t->timestamps();});
Schema::create('port_opener_node_credentials',function(Blueprint $t){$t->id();$t->unsignedBigInteger('node_id')->index();$t->string('token_hash',64)->unique();$t->timestamp('expires_at');$t->timestamp('revoked_at')->nullable();$t->timestamp('renewed_at')->nullable();$t->timestamps();});
Schema::table('port_opener_node_policies',function(Blueprint $t){$t->string('service_url',2048)->nullable();$t->text('service_token')->nullable();});
if(\Illuminate\Support\Str::isUuid((string)config('port-opener.installation_id')))\Illuminate\Support\Facades\DB::table('port_opener_node_policies')->where('node_id',(int)config('port-opener.node_id'))->update(['installation_id'=>config('port-opener.installation_id'),'service_enabled'=>true]);
}
public function down():void {throw new RuntimeException('Revoke services and verify router cleanup before removing outbound connection tables.');}
};
