<?php

return [
    'base_url' => env('IOCLOUD_BASE_URL', 'https://api.example.com'),
    'client_id' => env('IOCLOUD_CLIENT_ID'),
    'client_secret' => env('IOCLOUD_CLIENT_SECRET'),
    'timeout' => (float) env('IOCLOUD_TIMEOUT', 30),
];
