<?php
namespace PortOpener\Models;
use Illuminate\Database\Eloquent\Model;
class Exposure extends Model {
    protected $table = 'port_opener_exposures';
    protected $guarded = ['id'];
    protected function casts(): array { return ['ipv6_enabled'=>'boolean','enabled'=>'boolean', 'server_id'=>'integer','offline_since'=>'datetime','last_runtime_checked_at'=>'datetime']; }
}
