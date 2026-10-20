<?php
namespace PortOpener\Models;
use Illuminate\Database\Eloquent\Model;
class NodePolicy extends Model {
    protected $hidden=['service_token'];
    protected static function booted():void {static::saving(function(self $policy){if($policy->connection_mode==='outbound' && !$policy->installation_id)$policy->installation_id=(string)\Illuminate\Support\Str::uuid();if($policy->exists && ($policy->isDirty('router_url') || $policy->isDirty('forwarding_type')))$policy->test_request_id=null;});}
    public function getCredentialExpiresAtAttribute(){return NodeCredential::where('node_id',$this->node_id)->whereNull('revoked_at')->where('expires_at','>',now())->max('expires_at');}
    public function getTestRequestConfigurationAttribute($value){return $value ? json_decode($value,true) : null;}
    public function setTestRequestConfigurationAttribute($value){$this->attributes['test_request_configuration']=$value===null?null:json_encode($value,JSON_THROW_ON_ERROR);}
    protected $table = 'port_opener_node_policies';
    protected $guarded = ['id'];
    protected function casts(): array { return ['debug_until'=>'datetime','service_diagnostics'=>'array','ipv6_enabled'=>'boolean','ipv6_mapping_ids'=>'array','enabled'=>'boolean', 'node_id'=>'integer', 'min_port'=>'integer', 'max_port'=>'integer','last_router_test'=>'array','router_tested_at'=>'datetime','service_enabled'=>'boolean','service_dry_run'=>'boolean','lease_seconds'=>'integer','reconcile_seconds'=>'integer','last_contact_at'=>'datetime','last_report'=>'array','desired_snapshot'=>'array','desired_generation'=>'integer','service_token'=>'encrypted']; }
}
