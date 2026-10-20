<?php
namespace PortOpener\Models;use Illuminate\Database\Eloquent\Model;
class NodeCredential extends Model {protected $table='port_opener_node_credentials';protected $guarded=['id'];protected $hidden=['token_hash'];protected function casts():array{return ['expires_at'=>'datetime','revoked_at'=>'datetime','renewed_at'=>'datetime'];}}
