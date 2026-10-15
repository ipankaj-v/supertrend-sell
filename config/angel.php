<?php

return [
    'api_key' => env('ANGEL_API_KEY'),
    'client_code' => env('ANGEL_CLIENT_CODE'),
    'password' => env('ANGEL_PASSWORD'),
    'totp_secret' => env('ANGEL_TOTP_SECRET'),
    'client_local_ip' => env('ANGEL_CLIENT_LOCAL_IP', '127.0.0.1'),
    'client_public_ip' => env('ANGEL_CLIENT_PUBLIC_IP', '127.0.0.1'),
    'mac_address' => env('ANGEL_MAC_ADDRESS', '00:00:00:00:00:00'),
];
