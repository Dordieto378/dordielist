<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use App\Services\TmdbMovieService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MovieCategoryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_movies_category_and_interstellar_detail_show_tmdb_metadata(): void
    {
        $user = $this->manager();
        $movie = Media::where('source', 'tmdb')->where('source_id', 157336)->firstOrFail();
        $movie->update(['user_score' => null]);

        $this->actingAs($user)
            ->get(route('category', ['category' => 'movies']))
            ->assertOk()
            ->assertSee('MOVIES')
            ->assertSee('KEYWORDS')
            ->assertSee('PRODUCTION')
            ->assertSee('Interstellar')
            ->assertSee('Movies');

        $response = $this->actingAs($user)
            ->get(route('movies.show', $movie))
            ->assertOk()
            ->assertViewIs('media.anilist')
            ->assertSee('Release Date')
            ->assertSee('Runtime')
            ->assertSee('2h 49m')
            ->assertSee('Average Score')
            ->assertSee('85%')
            ->assertSee('Production')
            ->assertSee('Legendary Pictures')
            ->assertSee('wormhole')
            ->assertSee('Date Watched')
            ->assertSee('name="watched_date"', false)
            ->assertSee('name="user_score"', false)
            ->assertSee('value="0"', false)
            ->assertDontSee('name="list_start_date"', false)
            ->assertDontSee('name="list_end_date"', false)
            ->assertDontSee('Keywords:');

        $this->assertSame(1, substr_count($response->getContent(), 'Date Watched'));
    }

    public function test_movie_filters_use_tmdb_keywords_genres_and_production(): void
    {
        $user = $this->manager();

        foreach ([
            ['tags' => 'wormhole'],
            ['genre' => ['Science Fiction']],
            ['studio' => 'Syncopy'],
        ] as $filters) {
            $this->actingAs($user)
                ->get(route('category', ['category' => 'movies', ...$filters]))
                ->assertOk()
                ->assertSee('Interstellar');
        }

        $this->actingAs($user)
            ->get(route('category', ['category' => 'movies', 'studio' => 'Not A Production Company']))
            ->assertOk()
            ->assertDontSee('Interstellar');
    }

    public function test_movies_category_supports_collection_and_dordiewatch_filters(): void
    {
        $user = $this->manager();
        $includedMovie = Media::create([
            'type' => 'movie',
            'title_english' => 'Included Movie '.Str::random(8),
            'slug' => 'included-movie-'.Str::lower(Str::random(10)),
        ]);
        $excludedMovie = Media::create([
            'type' => 'movie',
            'title_english' => 'Excluded Movie '.Str::random(8),
            'slug' => 'excluded-movie-'.Str::lower(Str::random(10)),
        ]);
        $collection = Collection::create([
            'name' => 'Movie Collection '.Str::random(8),
            'is_system' => false,
        ]);

        CollectionItem::create([
            'collection_id' => $collection->id,
            'item_type' => 'movies',
            'item_id' => $includedMovie->id,
            'title' => $includedMovie->title_english,
        ]);

        DB::table('dordiewatch_media')->insert([
            'media_id' => $includedMovie->id,
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('category', ['category' => 'movies']))
            ->assertOk()
            ->assertSee('COLLECTION')
            ->assertSee($collection->name)
            ->assertSee('Ignore DordieWatch item')
            ->assertSee('Only show DordieWatch item');

        $this->actingAs($user)
            ->get(route('category', [
                'category' => 'movies',
                'collection' => (string) $collection->id,
                'dordiewatch_filter' => 'only',
            ]))
            ->assertOk()
            ->assertSee($includedMovie->title_english)
            ->assertDontSee($excludedMovie->title_english);
    }

    public function test_tmdb_service_imports_movie_details_and_metadata(): void
    {
        Http::fake([
            'api.themoviedb.org/3/movie/999001*' => Http::response([
                'id' => 999001,
                'title' => 'Test Movie',
                'original_title' => 'Test Movie Original',
                'overview' => 'A TMDb test movie.',
                'release_date' => '2026-09-20',
                'runtime' => 101,
                'vote_average' => 7.35,
                'status' => 'Released',
                'poster_path' => '/poster.jpg',
                'backdrop_path' => '/backdrop.jpg',
                'production_countries' => [['iso_3166_1' => 'US']],
                'genres' => [['id' => 80, 'name' => 'Crime']],
                'production_companies' => [['id' => 123456, 'name' => 'Test Production']],
                'keywords' => ['keywords' => [['id' => 654321, 'name' => 'test keyword']]],
            ]),
        ]);

        $movie = app(TmdbMovieService::class)->import(999001, 'test-token');

        $this->assertSame('movie', $movie->type);
        $this->assertSame('Test Movie', $movie->title_english);
        $this->assertSame(101, (int) $movie->runtime_minutes);
        $this->assertSame('7.35', (string) $movie->tmdb_vote_average);
        $this->assertSame(['Crime'], $movie->tmdbGenres->pluck('name')->all());
        $this->assertSame(['test keyword'], $movie->tmdbKeywords->pluck('name')->all());
        $this->assertSame(['Test Production'], $movie->tmdbProductionCompanies->pluck('name')->all());

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_movie_entry_status_change_moves_the_movie_between_tmdb_lists(): void
    {
        $user = $this->manager();
        $movie = Media::where('source', 'tmdb')->where('source_id', 157336)->firstOrFail();
        $user->forceFill([
            'tmdb_api_token' => 'test-token',
            'tmdb_session_id' => 'test-session',
        ])->save();
        $movie->update(['list_status' => 'CURRENT']);

        Http::fake([
            'api.themoviedb.org/3/list/8697654/add_item*' => Http::response([
                'success' => true,
                'status_code' => 12,
            ]),
            'api.themoviedb.org/3/list/8697811/remove_item*' => Http::response([
                'success' => true,
                'status_code' => 13,
            ]),
        ]);

        $this->actingAs($user)
            ->patch(route('movies.entry.update', $movie), [
                'user_score' => 92,
                'list_status' => 'COMPLETED',
                'watched_date' => '2026-09-20',
            ])
            ->assertRedirect();

        $movie->refresh();
        $this->assertSame(92, (int) $movie->user_score);
        $this->assertSame('COMPLETED', $movie->list_status);
        $this->assertSame('2026-09-20', (string) $movie->list_end_date);
        $this->assertNull($movie->list_start_date);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/list/8697654/add_item')
            && str_contains($request->url(), 'session_id=test-session')
            && (int) $request['media_id'] === 157336);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/list/8697811/remove_item')
            && str_contains($request->url(), 'session_id=test-session')
            && (int) $request['media_id'] === 157336);
        Http::assertSentCount(2);
    }

    public function test_scheduled_tmdb_import_command_imports_movies_from_the_configured_lists(): void
    {
        $user = $this->manager();
        $user->forceFill([
            'tmdb_api_token' => 'test-token',
            'tmdb_session_id' => 'test-session',
        ])->save();

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/list/8697654')) {
                return Http::response([
                    'items' => [['id' => 999002]],
                    'total_pages' => 1,
                ]);
            }

            if (str_contains($url, '/list/')) {
                return Http::response(['items' => [], 'total_pages' => 1]);
            }

            if (str_contains($url, '/movie/999002')) {
                return Http::response([
                    'id' => 999002,
                    'title' => 'Synced List Movie',
                    'original_title' => 'Synced List Movie',
                    'overview' => 'Imported from a configured TMDb list.',
                    'release_date' => '2026-09-21',
                    'runtime' => 95,
                    'vote_average' => 7.1,
                    'status' => 'Released',
                    'poster_path' => null,
                    'backdrop_path' => null,
                    'production_countries' => [],
                    'genres' => [],
                    'production_companies' => [],
                    'keywords' => ['keywords' => []],
                ]);
            }

            return Http::response([], 404);
        });

        $this->artisan('tmdb:import')
            ->expectsOutputToContain('TMDb sync complete')
            ->assertSuccessful();

        $this->assertDatabaseHas('media', [
            'source' => 'tmdb',
            'source_id' => 999002,
            'title_english' => 'Synced List Movie',
            'list_status' => 'COMPLETED',
        ]);
    }

    public function test_tmdb_account_can_be_connected_for_list_writes(): void
    {
        $user = $this->manager();
        $user->forceFill(['tmdb_api_token' => 'test-token'])->save();

        Http::fake([
            'api.themoviedb.org/3/authentication/token/new' => Http::response([
                'success' => true,
                'request_token' => 'request-token',
            ]),
            'api.themoviedb.org/3/authentication/session/new' => Http::response([
                'success' => true,
                'session_id' => 'account-session',
            ]),
        ]);

        $connectResponse = $this->actingAs($user)
            ->post(route('settings.api.tmdb.connect'));

        $connectResponse->assertRedirect();
        $this->assertStringContainsString(
            'https://www.themoviedb.org/authenticate/request-token',
            (string) $connectResponse->headers->get('Location'),
        );

        $this->get(route('settings.api.tmdb.callback'))
            ->assertRedirect(route('settings.api'))
            ->assertSessionHas('status', 'TMDb account connected.');

        $this->assertSame('account-session', $user->fresh()->tmdb_session_id);

        $this->get(route('settings.api'))
            ->assertOk()
            ->assertDontSee('TMDb Account')
            ->assertDontSee('Connect TMDb Account')
            ->assertDontSee('Sync TMDb Lists');
    }

    public function test_scheduled_tmdb_import_command_uses_saved_credentials(): void
    {
        $user = $this->manager();
        $user->forceFill([
            'tmdb_api_token' => 'scheduled-token',
            'tmdb_session_id' => 'scheduled-session',
        ])->save();

        Http::fake([
            'api.themoviedb.org/3/list/*' => Http::response([
                'items' => [],
                'total_pages' => 1,
            ]),
        ]);

        $this->artisan('tmdb:import')
            ->expectsOutputToContain('TMDb sync complete')
            ->assertSuccessful();

        Http::assertSentCount(6);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer scheduled-token')
            && str_contains($request->url(), 'session_id=scheduled-session'));
    }

    public function test_movie_can_be_deleted_locally(): void
    {
        $user = $this->manager();
        $movie = Media::create([
            'type' => 'movie',
            'title_english' => 'Delete Movie '.Str::random(6),
            'slug' => 'delete-movie-'.Str::lower(Str::random(10)),
            'source' => 'tmdb',
            'source_id' => 990000 + random_int(1, 9000),
        ]);

        $this->actingAs($user)
            ->delete(route('movies.destroy', $movie))
            ->assertRedirect(route('category', ['category' => 'movies']));

        $this->assertDatabaseMissing('media', ['id' => $movie->id]);
    }

    private function manager(): User
    {
        $role = Role::where('role', 'Admin')->first()
            ?? Role::create(['role' => 'Admin']);

        return User::create([
            'username' => 'movie-category-'.Str::lower(Str::random(10)),
            'email' => Str::lower(Str::random(12)).'@example.test',
            'password' => 'password',
            'status' => 'active',
            'role_id' => $role->role_id,
        ]);
    }
}
