<?php
namespace PortOpener\Models;
use Illuminate\Database\Eloquent\Model;
class EggRule extends Model {
    protected $table = 'port_opener_egg_rules';
    protected $guarded = ['id'];
    protected function casts(): array { return ['protocols'=>'array', 'public'=>'boolean', 'egg_id'=>'integer']; }
}
