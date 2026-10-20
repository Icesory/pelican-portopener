<?php

namespace PortOpener\Models;

use Illuminate\Database\Eloquent\Model;

class Ipv4Installation extends Model
{
    protected $table = 'port_opener_ipv4_installations';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['node_id' => 'integer', 'server_id' => 'integer', 'requested_by' => 'integer', 'started_at' => 'datetime', 'service_started_at' => 'datetime'];
    }

    public function pending(): bool
    {
        return !in_array($this->status, ['ready', 'failed'], true);
    }
}
