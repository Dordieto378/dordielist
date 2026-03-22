<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$rows = App\Models\Media::whereIn('id', [8997,9567,8965,9884,9812])->get(['id','title_romaji','cover_url','chapters_cnt'])->toArray();
var_export($rows);
