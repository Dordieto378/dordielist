<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $this->seedMovie();
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('media')
            ->where('source', 'tmdb')
            ->where('source_id', 157336)
            ->delete();
    }

    private function seedMovie(): void
    {
        $db = \Illuminate\Support\Facades\DB::table('media');
        $db->updateOrInsert(
            ['source' => 'tmdb', 'source_id' => 157336],
            [
                'type' => 'movie',
                'title_english' => 'Interstellar',
                'title_native' => 'Interstellar',
                'slug' => 'interstellar-tmdb-157336',
                'cover_url' => 'https://image.tmdb.org/t/p/original/gEU2QniE6E77NI6lCU6MxlNBvIx.jpg',
                'banner_url' => 'https://image.tmdb.org/t/p/original/xJHokMbljvjADYdit5fK5VQsXEG.jpg',
                'description' => 'Explorers travel through a wormhole in space in an attempt to ensure humanity survives.',
                'origin' => 'US',
                'media_status' => 'RELEASED',
                'year' => 2014,
                'start_date' => '2014-11-05',
                'runtime_minutes' => 169,
                'tmdb_vote_average' => 8.46,
            ]
        );

        $mediaId = $db->where('source', 'tmdb')->where('source_id', 157336)->value('id');
        if (! $mediaId) {
            return;
        }

        $this->attach($mediaId, 'tmdb_genres', 'tmdb_item_genre', 'genre_id', [
            [12, 'Adventure'],
            [18, 'Drama'],
            [878, 'Science Fiction'],
        ]);
        $this->attach($mediaId, 'tmdb_production_companies', 'tmdb_item_production_company', 'production_company_id', [
            [923, 'Legendary Pictures'],
            [9996, 'Syncopy'],
            [13769, 'Lynda Obst Productions'],
        ]);
        $this->attach($mediaId, 'tmdb_keywords', 'tmdb_item_keyword', 'keyword_id', [
            [null, 'space travel'],
            [null, 'wormhole'],
            [null, 'time dilation'],
            [null, 'father daughter relationship'],
            [null, 'survival of humanity'],
        ]);
    }

    private function attach(int $mediaId, string $table, string $pivot, string $foreignKey, array $records): void
    {
        foreach ($records as [$sourceId, $name]) {
            \Illuminate\Support\Facades\DB::table($table)->updateOrInsert(
                ['name' => $name],
                [
                    'slug' => \Illuminate\Support\Str::slug($name),
                    'source_id' => $sourceId,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $id = \Illuminate\Support\Facades\DB::table($table)->where('name', $name)->value('id');
            \Illuminate\Support\Facades\DB::table($pivot)->insertOrIgnore([
                'media_id' => $mediaId,
                $foreignKey => $id,
            ]);
        }
    }
};
