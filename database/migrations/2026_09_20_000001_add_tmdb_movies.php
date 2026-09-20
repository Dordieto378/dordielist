<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $types = ['anime', 'hentai', 'manga', 'manhwa', 'light_novel', 'doujin', 'vn', 'movie'];

    public function up(): void
    {
        $this->setMediaTypeEnum($this->types);

        Schema::table('media', function (Blueprint $table) {
            if (! Schema::hasColumn('media', 'runtime_minutes')) {
                $table->unsignedSmallInteger('runtime_minutes')->nullable()->after('volumes_cnt');
            }
            if (! Schema::hasColumn('media', 'tmdb_vote_average')) {
                $table->decimal('tmdb_vote_average', 4, 2)->nullable()->after('avg_score');
            }
        });
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'tmdb_api_token')) {
                $table->text('tmdb_api_token')->nullable()->after('anilist_access_token');
            }
        });

        foreach (['tmdb_genres', 'tmdb_keywords', 'tmdb_production_companies'] as $name) {
            if (! Schema::hasTable($name)) {
                Schema::create($name, function (Blueprint $table) {
                    $table->id();
                    $table->string('name')->unique();
                    $table->string('slug')->nullable()->index();
                    $table->unsignedBigInteger('source_id')->nullable()->unique();
                    $table->timestamps();
                });
            }
        }

        $this->createPivot('tmdb_item_genre', 'genre_id', 'tmdb_genres');
        $this->createPivot('tmdb_item_keyword', 'keyword_id', 'tmdb_keywords');
        $this->createPivot('tmdb_item_production_company', 'production_company_id', 'tmdb_production_companies');
    }

    public function down(): void
    {
        DB::table('media')->where('type', 'movie')->delete();

        foreach ([
            'tmdb_item_production_company', 'tmdb_item_keyword', 'tmdb_item_genre',
            'tmdb_production_companies', 'tmdb_keywords', 'tmdb_genres',
        ] as $name) {
            Schema::dropIfExists($name);
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'tmdb_api_token')) {
                $table->dropColumn('tmdb_api_token');
            }
        });
        Schema::table('media', function (Blueprint $table) {
            if (Schema::hasColumn('media', 'runtime_minutes')) {
                $table->dropColumn('runtime_minutes');
            }
            if (Schema::hasColumn('media', 'tmdb_vote_average')) {
                $table->dropColumn('tmdb_vote_average');
            }
        });

        $this->setMediaTypeEnum(array_values(array_diff($this->types, ['movie'])));
    }

    private function createPivot(string $name, string $foreignKey, string $foreignTable): void
    {
        if (Schema::hasTable($name)) {
            return;
        }

        Schema::create($name, function (Blueprint $table) use ($foreignKey, $foreignTable) {
            $table->unsignedBigInteger('media_id');
            $table->unsignedBigInteger($foreignKey);
            $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete();
            $table->foreign($foreignKey)->references('id')->on($foreignTable)->cascadeOnDelete();
            $table->primary(['media_id', $foreignKey]);
        });
    }

    private function setMediaTypeEnum(array $types): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $quoted = implode(',', array_map(
            static fn (string $type): string => "'".str_replace("'", "''", $type)."'",
            $types
        ));

        DB::statement("ALTER TABLE media MODIFY COLUMN type ENUM($quoted) NOT NULL");
    }
};
