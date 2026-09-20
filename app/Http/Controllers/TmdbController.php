<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Favorite;
use App\Models\Media;
use App\Services\TmdbMovieService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;

class TmdbController extends Controller
{
    public function __construct(private readonly TmdbMovieService $tmdb)
    {
    }

    public function show(Media $media)
    {
        abort_unless($media->type === 'movie', 404);
        $media->load(['tmdbGenres', 'tmdbKeywords', 'tmdbProductionCompanies']);
        $releaseDate = $media->start_date ? Carbon::parse($media->start_date) : null;

        return view('media.anilist', [
            'item' => [
                'id' => $media->id,
                'source' => $media->source,
                'sourceId' => $media->source_id,
                'type' => 'MOVIE',
                'title' => [
                    'english' => $media->title_english,
                    'romaji' => null,
                    'native' => $media->title_native,
                ],
                'coverImage' => ['extraLarge' => $media->cover_url ?: asset('images/no-image.jpg')],
                'bannerImage' => $media->banner_url,
                'description' => $media->description ?: '',
                'genres' => $media->tmdbGenres->pluck('name')->all(),
                'tags' => $media->tmdbKeywords->pluck('name')->all(),
                'studios' => $media->tmdbProductionCompanies->pluck('name')->all(),
                'averageScore' => $media->tmdb_vote_average,
                'tmdbVoteAverage' => $media->tmdb_vote_average,
                'runtimeMinutes' => $media->runtime_minutes,
                'status' => $media->media_status,
                'startDate' => [
                    'year' => $releaseDate?->year,
                    'month' => $releaseDate?->month,
                    'day' => $releaseDate?->day,
                ],
                'mediaListEntry' => [
                    'score' => $media->user_score,
                    'status' => $media->list_status,
                ],
                'userScore' => $media->user_score,
                'userProgress' => null,
                'listStatus' => $media->list_status,
                'listStartDate' => $media->list_start_date,
                'listEndDate' => $media->list_end_date,
                'watchedDate' => $media->list_end_date,
            ],
            'id' => $media->id,
            'category' => 'movies',
            'isFavorited' => Favorite::where('favoritable_type', 'movies')
                ->where('favoritable_id', $media->id)
                ->exists(),
            'allCollections' => Collection::orderBy('is_system', 'desc')->orderBy('name')->get(),
            'attachedIds' => CollectionItem::where('item_type', 'movies')
                ->where('item_id', $media->id)
                ->pluck('collection_id')
                ->all(),
            'dordieWatchLaunchUrl' => $this->dordieWatchLaunchUrl($media),
        ]);
    }

    public function import(Request $request)
    {
        abort_if(optional($request->user()?->role)->role === 'Viewer', 403);
        $data = $request->validate(['tmdb_id' => ['required', 'integer', 'min:1']]);
        $token = $request->user()?->tmdb_api_token ?: config('services.tmdb.token');

        if (! $token) {
            return back()->with('error', 'Add your TMDb API Read Access Token in API settings first.');
        }

        try {
            $movie = $this->tmdb->import((int) $data['tmdb_id'], $token);
        } catch (\Throwable $exception) {
            return back()->with('error', 'TMDb import failed: '.$exception->getMessage());
        }

        return redirect()->route('movies.show', $movie);
    }

    public function refresh(Request $request, Media $media)
    {
        abort_unless($media->type === 'movie' && $media->source === 'tmdb' && $media->source_id, 404);
        abort_if(optional($request->user()?->role)->role === 'Viewer', 403);
        $token = $request->user()?->tmdb_api_token ?: config('services.tmdb.token');

        if (! $token) {
            return back()->with('error', 'Add your TMDb API Read Access Token in API settings first.');
        }

        try {
            $this->tmdb->import((int) $media->source_id, $token);
        } catch (\Throwable $exception) {
            return back()->with('error', 'TMDb refresh failed: '.$exception->getMessage());
        }

        return back()->with('success', 'Movie details refreshed from TMDb.');
    }

    public function updateEntry(Request $request, Media $media)
    {
        abort_unless($media->type === 'movie', 404);
        abort_if(optional($request->user()?->role)->role === 'Viewer', 403);

        $validator = Validator::make($request->all(), [
            'user_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'list_status' => ['required', 'in:CURRENT,PLANNING,COMPLETED,PAUSED,DROPPED,REPEATING'],
            'watched_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            return back()
                ->withInput()
                ->with('open_edit_entry_modal', true)
                ->with('entry_update_error', $validator->errors()->first());
        }

        $data = $validator->validated();

        $media->update([
            'user_score' => $data['user_score'] ?? null,
            'list_status' => $data['list_status'],
            'list_start_date' => null,
            'list_end_date' => $data['watched_date'] ?? null,
        ]);

        return back();
    }

    public function destroy(Request $request, Media $media)
    {
        abort_unless($media->type === 'movie', 404);
        abort_if(optional($request->user()?->role)->role === 'Viewer', 403);

        DB::transaction(function () use ($media) {
            Favorite::where('favoritable_type', 'movies')
                ->where('favoritable_id', $media->id)
                ->delete();
            CollectionItem::where('item_type', 'movies')
                ->where('item_id', $media->id)
                ->delete();
            $media->delete();
        });

        return redirect()->route('category', ['category' => 'movies']);
    }

    private function dordieWatchLaunchUrl(Media $media): ?string
    {
        if (! DB::table('dordiewatch_media')->where('media_id', $media->id)->exists()) {
            return null;
        }

        $manifestUrl = URL::temporarySignedRoute(
            'dordiewatch.media',
            now()->addMinutes(10),
            ['media' => $media->id]
        );

        return 'dordiewatch://open?manifest='.rtrim(strtr(base64_encode($manifestUrl), '+/', '-_'), '=');
    }
}
