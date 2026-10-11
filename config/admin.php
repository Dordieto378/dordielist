<?php

return [
    // Used only to create the first administrator during migration.
    'email' => env('ADMIN_EMAIL'),
    'username' => env('ADMIN_USERNAME', 'admin'),
    'password' => env('ADMIN_PASSWORD'),
];
