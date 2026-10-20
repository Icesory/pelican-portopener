<?php
return [
    'endpoint' => env('PORT_OPENER_ENDPOINT', 'http://127.0.0.1:9876'),
    'token' => env('PORT_OPENER_TOKEN', ''),
    'installation_id' => env('PORT_OPENER_INSTALLATION_ID', ''),
    'node_id' => (int) env('PORT_OPENER_NODE_ID', 0),
    'enabled' => (bool) env('PORT_OPENER_ENABLED', false),
];
