<?php
namespace PortOpener\Models;use Illuminate\Database\Eloquent\Model;
class Pairing extends Model {protected $table='port_opener_pairings';protected $guarded=['id'];protected $hidden=['code_hash'];protected function casts():array{return ['expires_at'=>'datetime','used_at'=>'datetime'];}}
