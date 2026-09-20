<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use App\Services\TmdbMovieService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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

    public function test_movie_entry_edits_are_local_and_use_one_watched_date(): void
    {
        $user = $this->manager();
        $movie = Media::where('source', 'tmdb')->where('source_id', 157336)->firstOrFail();
        Http::fake();

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
        Http::assertNothingSent();
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
