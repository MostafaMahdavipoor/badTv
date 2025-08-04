<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Bot Connection
    |--------------------------------------------------------------------------
    |
    | The bot connection with which the request is sent by default.
    |
    */

    'default' => 'bot',

    'connections' => [
        'bot' => [
            'token' => '8028621605:AAFGZ-R1rad9fZQY62lU6EXt0yH-K5qBR4I',
            'url' => 'https://7445fc4412f2.ngrok-free.app',
            'username' => '',
            'userid' => '',
            'secret_token' => null,
            'allowed_updates' => ['*']
        ],
    ],

    'api_server' => [
        'endpoint' => 'https://api.telegram.org',
        'dir' => storage_path('app/api-server'),
        'log_dir' => '',
        'ip' => '127.0.0.1',
        'port' => 8081,
        'stat' => [
            'ip' => '',
            'port' => ''
        ],
        'api_id' => '',
        'api_hash' => ''
    ],

];