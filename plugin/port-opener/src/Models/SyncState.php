<?php
namespace PortOpener\Models;
use Illuminate\Database\Eloquent\Model;
class SyncState extends Model {
    protected $table = 'port_opener_sync_states';
    protected $guarded = ['id'];
    protected function casts(): array { return ['generation'=>'integer', 'last_status'=>'array', 'synced_at'=>'datetime']; }
}
