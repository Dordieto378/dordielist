<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class DordieWatchIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_signed_manifest_exposes_video_metadata_by_database_id(): void
    {
        $media = Media::create([
            'type' => 'anime',
            'title_english' => 'DordieWatch Test',
            'title_romaji' => 'DordieWatch Test Romaji',
            'cover_url' => 'https://example.test/cover.jpg',
            'episodes_cnt' => 12,
            'year' => 2026,
            'slug' => 'dordiewatch-test-'.Str::lower(Str::random(12)),
        ]);

        $url = URL::temporarySignedRoute(
            'dordiewatch.media',
            now()->addMinute(),
            ['media' => $media->id]
        );

        $this
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('id', $media->id)
            ->assertJsonPath('type', 'anime')
            ->assertJsonPath('title.english', 'DordieWatch Test')
            ->assertJsonPath('cover_url', 'https://example.test/cover.jpg')
            ->assertJsonPath('episodes', 12)
            ->assertJson(fn ($json) => $json
                ->whereType('library_url', 'string')
                ->etc());
    }

    public function test_signed_manifest_exposes_movie_metadata(): void
    {
        $movie = Media::create([
            'type' => 'movie',
            'source' => 'tmdb',
            'source_id' => 999001,
            'title_english' => 'DordieWatch Movie Test',
            'cover_url' => 'https://example.test/movie.jpg',
            'slug' => 'dordiewatch-movie-test-'.Str::lower(Str::random(12)),
        ]);

        $url = URL::temporarySignedRoute(
            'dordiewatch.media',
            now()->addMinute(),
            ['media' => $movie->id]
        );

        $this
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('id', $movie->id)
            ->assertJsonPath('type', 'movie')
            ->assertJsonPath('display_title', 'DordieWatch Movie Test')
            ->assertJsonPath('cover_url', 'https://example.test/movie.jpg')
            ->assertJsonPath('website_url', route('movies.show', ['media' => $movie->id]));
    }

    public function test_unsigned_manifest_is_rejected(): void
    {
        $media = Media::create([
            'type' => 'hentai',
            'title_english' => 'Unsigned Test',
            'slug' => 'unsigned-test-'.Str::lower(Str::random(12)),
        ]);

        $this
            ->getJson(route('dordiewatch.media', ['media' => $media->id]))
            ->assertForbidden();
    }

    public function test_local_config_returns_a_working_signed_library_url(): void
    {
        $media = Media::create([
            'type' => 'anime',
            'title_english' => 'Config Signed Library Test',
            'slug' => 'config-signed-library-test-'.Str::lower(Str::random(12)),
        ]);

        $libraryUrl = $this
            ->getJson(route('dordiewatch.config'))
            ->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJson(fn ($json) => $json
                ->whereType('library_url', 'string')
                ->etc())
            ->json('library_url');

        $this
            ->postJson($libraryUrl, ['ids' => [$media->id]])
            ->assertOk()
            ->assertJsonPath('media.0.id', $media->id);
    }

    public function test_config_is_only_available_to_local_requests(): void
    {
        $this
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->getJson(route('dordiewatch.config'))
            ->assertForbidden();
    }

    public function test_signed_library_refresh_returns_requested_watchable_media_by_id(): void
    {
        $anime = Media::create([
            'type' => 'anime',
            'title_english' => 'Refresh Anime',
            'cover_url' => 'https://example.test/anime.jpg',
            'slug' => 'refresh-anime-'.Str::lower(Str::random(12)),
        ]);
        $hentai = Media::create([
            'type' => 'hentai',
            'title_english' => 'Refresh Hentai',
            'slug' => 'refresh-hentai-'.Str::lower(Str::random(12)),
        ]);
        $movie = Media::create([
            'type' => 'movie',
            'title_english' => 'Refresh Movie',
            'slug' => 'refresh-movie-'.Str::lower(Str::random(12)),
        ]);
        $manga = Media::create([
            'type' => 'manga',
            'title_english' => 'Do Not Refresh Manga',
            'slug' => 'refresh-manga-'.Str::lower(Str::random(12)),
        ]);
        $stale = Media::create([
            'type' => 'anime',
            'title_english' => 'No Longer Local',
            'slug' => 'refresh-stale-'.Str::lower(Str::random(12)),
        ]);
        DB::table('dordiewatch_media')->insert([
            'media_id' => $stale->id,
            'last_seen_at' => now()->subHour(),
        ]);

        $url = URL::signedRoute('dordiewatch.library');

        $this
            ->postJson($url, [
                'ids' => [$hentai->id, $manga->id, $movie->id, $anime->id, $anime->id],
            ])
            ->assertOk()
            ->assertJsonCount(3, 'media')
            ->assertJsonPath('available_ids', [$hentai->id, $movie->id, $anime->id])
            ->assertJsonPath('media.0.id', $hentai->id)
            ->assertJsonPath('media.1.id', $movie->id)
            ->assertJsonPath('media.1.type', 'movie')
            ->assertJsonPath('media.2.id', $anime->id)
            ->assertJsonPath('media.2.cover_url', 'https://example.test/anime.jpg');

        $this->assertDatabaseHas('dordiewatch_media', ['media_id' => $anime->id]);
        $this->assertDatabaseHas('dordiewatch_media', ['media_id' => $hentai->id]);
        $this->assertDatabaseHas('dordiewatch_media', ['media_id' => $movie->id]);
        $this->assertDatabaseMissing('dordiewatch_media', ['media_id' => $manga->id]);
        $this->assertDatabaseMissing('dordiewatch_media', ['media_id' => $stale->id]);

        $this
            ->postJson(route('dordiewatch.library'), ['ids' => [$anime->id]])
            ->assertForbidden();
    }

    public function test_anime_detail_page_only_contains_watch_link_when_available_locally(): void
    {
        $role = Role::where('role', 'Viewer')->first()
            ?? Role::create(['role' => 'Viewer']);
        $user = User::create([
            'username' => 'dordiewatch-'.Str::lower(Str::random(10)),
            'email' => Str::lower(Str::random(12)).'@example.test',
            'password' => 'password',
            'status' => 'active',
            'role_id' => $role->role_id,
        ]);
        $media = Media::create([
            'type' => 'anime',
            'title_english' => 'Desktop Link Test',
            'slug' => 'desktop-link-test-'.Str::lower(Str::random(12)),
        ]);

        $this
            ->actingAs($user)
            ->get(route('media.show', ['id' => $media->id]))
            ->assertOk()
            ->assertDontSee('Watch in DordieWatch')
            ->assertDontSee('dordiewatch://open?manifest=', false);

        DB::table('dordiewatch_media')->insert([
            'media_id' => $media->id,
            'last_seen_at' => now(),
        ]);

        $this
            ->actingAs($user)
            ->get(route('media.show', ['id' => $media->id]))
            ->assertOk()
            ->assertSee('Watch in DordieWatch')
            ->assertSee('dordiewatch://open?manifest=', false);
    }

    public function test_category_cards_do_not_show_play_link_when_available_locally(): void
    {
        $role = Role::where('role', 'Viewer')->first()
            ?? Role::create(['role' => 'Viewer']);
        $user = User::create([
            'username' => 'dordiewatch-category-'.Str::lower(Str::random(10)),
            'email' => Str::lower(Str::random(12)).'@example.test',
            'password' => 'password',
            'status' => 'active',
            'role_id' => $role->role_id,
        ]);
        $media = Media::create([
            'type' => 'anime',
            'title_english' => '000 DordieWatch Category Link Test',
            'slug' => 'category-link-test-'.Str::lower(Str::random(12)),
        ]);

        DB::table('dordiewatch_media')->insert([
            'media_id' => $media->id,
            'last_seen_at' => now(),
        ]);

        $this
            ->actingAs($user)
            ->get(route('category', ['category' => 'ANIMES']))
            ->assertOk()
            ->assertSee('000 DordieWatch Category')
            ->assertDontSee('Play in DordieWatch')
            ->assertDontSee('dordiewatch://open?manifest=', false);
    }

    public function test_movie_detail_page_contains_watch_link_when_available_locally(): void
    {
        $role = Role::where('role', 'Viewer')->first()
            ?? Role::create(['role' => 'Viewer']);
        $user = User::create([
            'username' => 'dordiewatch-movie-'.Str::lower(Str::random(10)),
            'email' => Str::lower(Str::random(12)).'@example.test',
            'password' => 'password',
            'status' => 'active',
            'role_id' => $role->role_id,
        ]);
        $movie = Media::create([
            'type' => 'movie',
            'source' => 'tmdb',
            'source_id' => 999002,
            'title_english' => 'DordieWatch Movie Link Test',
            'slug' => 'movie-link-test-'.Str::lower(Str::random(12)),
        ]);

        DB::table('dordiewatch_media')->insert([
            'media_id' => $movie->id,
            'last_seen_at' => now(),
        ]);

        $this
            ->actingAs($user)
            ->get(route('movies.show', ['media' => $movie->id]))
            ->assertOk()
            ->assertSee('Watch in DordieWatch')
            ->assertSee('dordiewatch://open?manifest=', false);
    }
}
