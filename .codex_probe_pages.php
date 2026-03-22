<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$chapterIds = App\Models\Chapter::whereIn('media_fk', [8997,9567,8965,9884,9812])->pluck('id');
$rows = App\Models\ChapterPage::whereIn('chapter_id', $chapterIds)->select('chapter_id','page_number','file_path')->orderBy('chapter_id')->orderBy('page_number')->limit(30)->get()->toArray();
var_export($rows);
