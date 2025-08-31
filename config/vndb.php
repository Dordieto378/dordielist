<?php
return [
  // JSON socket host (not the HTTP API host)
  'host'     => 'vndb.org',
  'port'     => 19535,
  'username' => env('VNDB_USERNAME'),
  'password' => env('VNDB_PASSWORD'),
];
