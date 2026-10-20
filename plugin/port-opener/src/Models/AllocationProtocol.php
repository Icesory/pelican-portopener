<?php

namespace PortOpener\Models;

use Illuminate\Database\Eloquent\Model;

class AllocationProtocol extends Model
{
    protected $table = 'port_opener_allocation_protocols';
    protected $guarded = [];
    protected function casts(): array { return ['protocols' => 'array']; }
}
