<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $legacyType = 'man'.'wha';
        $correctType = 'manhwa';

        $this->setMediaTypeEnum(['anime', 'hentai', 'manga', $legacyType, $correctType, 'doujin', 'vn']);
        $this->replaceStoredValues($legacyType, $correctType);
        $this->setMediaTypeEnum(['anime', 'hentai', 'manga', $correctType, 'doujin', 'vn']);
    }

    public function down(): void
    {
        $legacyType = 'man'.'wha';
        $correctType = 'manhwa';

        $this->setMediaTypeEnum(['anime', 'hentai', 'manga', $legacyType, $correctType, 'doujin', 'vn']);
        $this->replaceStoredValues($correctType, $legacyType);
        $this->setMediaTypeEnum(['anime', 'hentai', 'manga', $legacyType, 'doujin', 'vn']);
    }

    private function replaceStoredValues(string $fromType, string $toType): void
    {
        foreach ($this->storedTypeColumns() as [$table, $column]) {
            $this->replaceStoredValue($table, $column, $fromType, $toType);
            $this->replaceStoredValue($table, $column, $fromType.'s', $toType.'s');
            $this->replaceStoredValue($table, $column, strtoupper($fromType), strtoupper($toType));
        }
    }

    private function replaceStoredValue(string $table, string $column, string $from, string $to): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)->where($column, $from)->update([$column => $to]);
    }

    private function storedTypeColumns(): array
    {
        return [
            ['media', 'type'],
            ['favorites', 'favoritable_type'],
            ['collection_items', 'item_type'],
            ['chapters', 'item_type'],
            ['episodes', 'media_type'],
        ];
    }

    private function setMediaTypeEnum(array $types): void
    {
        $quotedTypes = implode(',', array_map(
            static fn (string $type): string => "'".str_replace("'", "''", $type)."'",
            $types
        ));

        DB::statement("ALTER TABLE media MODIFY COLUMN type ENUM($quotedTypes) NOT NULL");
    }
};