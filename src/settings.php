<?php
return [
    'settings' => [
        'displayErrorDetails' => true, // set to false in production
        'addContentLengthHeader' => false, // Allow the web server to send the content-length header

        // Renderer settings
        'renderer' => [
            'template_path' => __DIR__ . '/../templates/',
        ],

        // Monolog settings
        'logger' => [
            'name' => 'slim-app',
            'path' => isset($_ENV['docker']) ? 'php://stdout' : __DIR__ . '/../logs/app.log',
            'level' => \Monolog\Logger::DEBUG,
        ],

        // Bunq settings
        'bunq' => [
            'client_id' => getenv('BUNQ_CLIENT_ID'),
            'client_secret' => getenv('BUNQ_CLIENT_SECRET'),
            'auth_url' => 'https://oauth.sandbox.bunq.com/auth',
            'token_url' => 'https://api-oauth.sandbox.bunq.com/v1/token',
        ]
    ],
];
