<?php
// Execute only inside isolated Docker panel: php plugins/port-opener/tests/dev-fixture.php
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('app.name') !== 'PortOpener Development' || !str_contains((string)config('database.connections.sqlite.database'), '/pelican-data/')) throw new RuntimeException('Refusing fixtures outside the isolated development panel.');
use App\Models\{User,Node,Egg,Server,Allocation,EggVariable,ServerVariable};
use Illuminate\Support\Str;
use PortOpener\Models\{NodePolicy,EggRule,Exposure};
// Disable model observers: fixture construction must never call Wings or queue reconciles.
\Illuminate\Database\Eloquent\Model::withoutEvents(function () {
    $owner = User::where('username','dev-owner')->firstOrFail();
    $node = Node::where('name','PortOpener Synthetic Node')->first();
    if (!$node) $node = Node::forceCreate(['name'=>'PortOpener Synthetic Node',
        'uuid'=>(string)Str::uuid(),'fqdn'=>'wings.invalid','scheme'=>'http','public'=>false,
        'daemon_token_id'=>'fixture-token-id','daemon_token'=>Str::random(64),'memory'=>1024,'disk'=>10240,'cpu'=>100,'upload_size'=>100,
    ]);
    $egg = Egg::firstOrCreate(['name'=>'PortOpener Synthetic Egg'],[
        'uuid'=>(string)Str::uuid(),'author'=>'dev@example.invalid','docker_images'=>['fixture'=>'busybox:latest'],
        'startup_commands'=>['fixture'=>'echo synthetic'],'config_logs'=>'{}','config_startup'=>'{}','config_files'=>'{}','config_stop'=>'stop',
    ]);
    $allocation = Allocation::firstOrCreate(['node_id'=>$node->id,'ip'=>'192.168.178.50','port'=>54321]);
    $server = Server::firstOrCreate(['name'=>'PortOpener Synthetic Server'],[
        'owner_id'=>$owner->id,'node_id'=>$node->id,'egg_id'=>$egg->id,'allocation_id'=>$allocation->id,
        'uuid'=>(string)Str::uuid(),'uuid_short'=>Str::lower(Str::random(8)),'description'=>'Synthetic fixture; no Wings exists.',
        'status'=>null,'memory'=>512,'swap'=>0,'disk'=>512,'io'=>500,'cpu'=>0,'startup'=>'echo fixture','image'=>'busybox:latest','database_limit'=>0,'backup_limit'=>0,
    ]);
    $allocation->update(['server_id'=>$server->id]);
    $query = Allocation::firstOrCreate(['node_id'=>$node->id,'ip'=>'192.168.178.50','port'=>54322],['server_id'=>$server->id]);
    $var = EggVariable::firstOrCreate(['egg_id'=>$egg->id,'env_variable'=>'QUERY_PORT'],[
        'name'=>'Query port','description'=>'Synthetic UDP allocation','default_value'=>'54322','user_viewable'=>true,'user_editable'=>true,'rules'=>'required|integer',
    ]);
    ServerVariable::updateOrCreate(['server_id'=>$server->id,'variable_id'=>$var->id],['variable_value'=>'54322']);
    NodePolicy::updateOrCreate(['node_id'=>$node->id],['enabled'=>true,'internal_address'=>'192.168.178.50','min_port'=>54000,'max_port'=>54999]);
    EggRule::updateOrCreate(['egg_id'=>$egg->id,'name'=>'Game'],['source'=>'primary_allocation','variable_name'=>null,'protocols'=>['TCP','UDP'],'public'=>true]);
    EggRule::updateOrCreate(['egg_id'=>$egg->id,'name'=>'Query'],['source'=>'variable','variable_name'=>'QUERY_PORT','protocols'=>['UDP'],'public'=>true]);
    Exposure::firstOrCreate(['server_id'=>$server->id],['enabled'=>false]);
    echo json_encode(['node_id'=>$node->id,'egg_id'=>$egg->id,'server_id'=>$server->id,'server_uuid'=>$server->uuid],JSON_PRETTY_PRINT)."\n";
});
