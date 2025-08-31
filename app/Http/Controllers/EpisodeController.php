<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\Episode;
use App\Models\Favorite;
use App\Models\Collection;
use App\Models\CollectionItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class EpisodeController extends Controller
{
    public function store(Request $request, $mediaId)
    {   
        set_time_limit(0);   
        // Validate first...
        $request->validate([
            'videos'   => ['required','array','min:1'],
            'videos.*' => ['file','mimetypes:video/mp4,video/webm'],
        ]);

        // B2 authorize
        $accountId      = env('B2_KEY_ID');
        $applicationKey = env('B2_APP_KEY');
        $bucketName     = env('B2_BUCKET');

        $authResp = Http::withBasicAuth($accountId, $applicationKey)
            ->get('https://api.backblazeb2.com/b2api/v2/b2_authorize_account');
        $authData  = $authResp->json();
        $apiUrl    = $authData['apiUrl'];
        $authToken = $authData['authorizationToken'];

        // Get bucketId
        $listBuckets   = Http::withHeaders(['Authorization' => $authToken])
                            ->post("{$apiUrl}/b2api/v2/b2_list_buckets", [
                                'accountId'  => $accountId,
                                'bucketName' => $bucketName,
                                'bucketId'   => null,
                            ])->json();
        $bucketId = $listBuckets['buckets'][0]['bucketId'];

        $uploadData = Http::withHeaders(['Authorization' => $authToken])
            ->post("{$apiUrl}/b2api/v2/b2_get_upload_url", [
                'bucketId' => $bucketId,
            ])->json();

        $uploadUrl       = $uploadData['uploadUrl'];
        $uploadAuthToken = $uploadData['authorizationToken'];

        // Determine next episode number
        $nextEp = (Episode::where('media_id',$mediaId)->max('episode_number') ?? 0) + 1;

        foreach ($request->file('videos') as $file) {

            $mediaType  = $request->input('media_type', 'ANIME');
            $remotePath = "{$mediaType}/{$mediaId}/".$file->getClientOriginalName();

            // Encode path *segments* but keep slashes
            $encoded    = str_replace('%2F', '/', rawurlencode($remotePath));

            $sha1 = hash_file('sha1', $file->getRealPath());

            Http::timeout(0)
                ->withHeaders([
                    'Authorization'     => $uploadAuthToken,
                    'X-Bz-File-Name'    => $encoded,
                    'Content-Type'      => $file->getClientMimeType(),
                    'X-Bz-Content-Sha1' => $sha1,
                ])
                ->withBody(
                    fopen($file->getRealPath(), 'rb'),
                    $file->getClientMimeType()
                )
                ->post($uploadUrl)
                ->throw();

            Episode::create([
                'media_id'       => $mediaId,
                'media_type'     => $mediaType,
                'episode_number' => $nextEp++,
                'file_path'      => $remotePath,
            ]);
        }


        return response()->json(['message' => 'Episodes uploaded successfully!']);
    }

    public function show($mediaId, $episodeNumber)
    {
        $accessToken = env('ANILIST_ACCESS_TOKEN');
        $item = $this->fetchSingleItem($mediaId, $accessToken);
        if (! $item) {
            abort(404, 'Media not found on AniList.');
        }

        $type     = strtoupper($item['type'] ?? '');
        $genres   = $item['genres'] ?? [];
        $origin   = strtoupper($item['countryOfOrigin'] ?? '');
        if ($type === 'ANIME' && in_array('Hentai', $genres, true)) {
            $category = 'hentai';
        } elseif ($type === 'ANIME') {
            $category = 'anime';
        } elseif ($type === 'MANGA') {
            // first check for Korea
            if ($origin === 'KR') {
                $category = 'manwha';
            } else {
                $category = 'manga';
            }
        } 

        $episode = Episode::where('media_id', $mediaId)
                        ->where('episode_number', $episodeNumber)
                        ->firstOrFail();

            return view('episodes.show', [
            'item'           => $item,
            'episode'        => $episode,
            'category'       => $category,
        ]);
    }

    protected function fetchSingleItem(int $mediaId, string $accessToken)
    {
        $url = 'https://graphql.anilist.co';
        $query = <<<'GQL'
        query ($id: Int) {
            Media(id: $id) {
                id
                type
                title {
                    english
                    romaji
                }
                coverImage { extraLarge }
                description
                genres
                tags { name }
                averageScore
                episodes
                isAdult
                startDate { year month day }
                countryOfOrigin
            }
        }
        GQL;

        $response = Http::withHeaders([
            'Authorization' => "Bearer $accessToken",
            'Content-Type'  => 'application/json',
        ])->post($url, [
            'query'     => $query,
            'variables' => ['id' => $mediaId],
        ]);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json('data.Media');
        // Strip HTML from description if needed
        if (isset($data['description'])) {
            $data['description'] = strip_tags($data['description']);
        }
        return $data;
    }
}
