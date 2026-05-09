<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doujin_authors', function (Blueprint $table) {
            if (!Schema::hasColumn('doujin_authors', 'twitter_url')) {
                $table->string('twitter_url', 2048)->nullable()->after('slug');
            }

            if (!Schema::hasColumn('doujin_authors', 'patreon_url')) {
                $table->string('patreon_url', 2048)->nullable()->after('twitter_url');
            }

            if (!Schema::hasColumn('doujin_authors', 'fanbox_url')) {
                $table->string('fanbox_url', 2048)->nullable()->after('patreon_url');
            }

            if (!Schema::hasColumn('doujin_authors', 'pixiv_url')) {
                $table->string('pixiv_url', 2048)->nullable()->after('fanbox_url');
            }
        });

        if (
            Schema::hasColumn('media', 'doujin_twitter_url')
            || Schema::hasColumn('media', 'doujin_patreon_url')
            || Schema::hasColumn('media', 'doujin_fanbox_url')
            || Schema::hasColumn('media', 'doujin_pixiv_url')
        ) {
            $rows = DB::table('doujin_authors')
                ->join('doujin_item_author', 'doujin_authors.id', '=', 'doujin_item_author.author_id')
                ->join('media', 'media.id', '=', 'doujin_item_author.media_id')
                ->select([
                    'doujin_authors.id as author_id',
                    'media.doujin_twitter_url',
                    'media.doujin_patreon_url',
                    'media.doujin_fanbox_url',
                    'media.doujin_pixiv_url',
                ])
                ->where('media.type', 'doujin')
                ->get();

            foreach ($rows as $row) {
                $updates = [];

                foreach ([
                    'twitter_url' => 'doujin_twitter_url',
                    'patreon_url' => 'doujin_patreon_url',
                    'fanbox_url' => 'doujin_fanbox_url',
                    'pixiv_url' => 'doujin_pixiv_url',
                ] as $authorColumn => $mediaColumn) {
                    $value = trim((string) ($row->{$mediaColumn} ?? ''));
                    if ($value !== '') {
                        $updates[$authorColumn] = $value;
                    }
                }

                foreach ($updates as $column => $value) {
                    DB::table('doujin_authors')
                        ->where('id', $row->author_id)
                        ->where(function ($query) use ($column) {
                            $query->whereNull($column)->orWhere($column, '');
                        })
                        ->update([$column => $value]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('doujin_authors', function (Blueprint $table) {
            foreach (['pixiv_url', 'fanbox_url', 'patreon_url', 'twitter_url'] as $column) {
                if (Schema::hasColumn('doujin_authors', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
