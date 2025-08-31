<?php
// app/Http/Controllers/ChapterController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Chapter;
use App\Models\ChapterPage;
use Illuminate\Support\Facades\Storage;

class ChapterController extends Controller
{
    public function show(int $mediaId, int $chapterNumber)
    {
        $chapter = Chapter::where('item_id', $mediaId)
                        ->where('chapter_number', $chapterNumber)
                        ->with('pages')
                        ->firstOrFail();

        return view('chapters.show', [
            'chapter'   => $chapter,
            'pages'     => $chapter->pages->sortBy('chapter_number'),
            'mediaId'   => $mediaId,
        ]);
    }

    public function readPage(int $mediaId, int $chapterNumber, int $pageNumber)
    {
        $chapter = Chapter::with('pages')
                        ->where('item_id', $mediaId)
                        ->where('chapter_number', $chapterNumber)
                        ->firstOrFail();

        $pages = $chapter->pages->sortBy('chapter_number')->values();

        // build the page URL, prev/next logic exactly like you do for doujins
        $pageModel = $pages->firstWhere('chapter_number', $pageNumber);
        $pageUrl   = Storage::disk('b2')->url($pageModel->file_path);

        $numbers = $pages->pluck('chapter_number')->all();
        $idx     = array_search($pageNumber, $numbers, true);
        $prev    = $idx > 0                  ? $numbers[$idx-1]         : null;
        $next    = $idx < count($numbers)-1  ? $numbers[$idx+1]         : null;

        return view('chapters.read', [
            'chapter'    => $chapter,
            'pages'      => $pages,
            'pageNumber' => $pageNumber,
            'pageUrl'    => $pageUrl,
            'prevPage'   => $prev,
            'nextPage'   => $next,
        ]);
    }

    public function store(Request $request, $mediaId)
    {
        // 1) Validate the chapter number + page files
        $request->validate([
            'title'     => ['required','integer','min:1'],
            'files'     => ['required','array','min:1'],
            'files.*'   => ['file','mimetypes:image/png,image/jpeg,image/webp'],
        ]);

        // 2) B2 authorization (same as before)
        $acct   = env('B2_KEY_ID');
        $appKey = env('B2_APP_KEY');
        $bucket = env('B2_BUCKET');

        $authResp = Http::withBasicAuth($acct, $appKey)
            ->get('https://api.backblazeb2.com/b2api/v2/b2_authorize_account')
            ->json();

        $apiUrl    = $authResp['apiUrl'];
        $authToken = $authResp['authorizationToken'];

        $buckets = Http::withHeaders(['Authorization' => $authToken])
            ->post("{$apiUrl}/b2api/v2/b2_list_buckets", [
                'accountId'  => $acct,
                'bucketName' => $bucket,
            ])->json();

        $bucketId = $buckets['buckets'][0]['bucketId'];

        // 3) Create the chapter row
        $chapter = Chapter::create([
            'item_type'      => $request->input('media_type','manga'),
            'item_id'        => $mediaId,
            'chapter_number' => $request->input('title'),
        ]);

        // 4) Loop through each uploaded page
        foreach ($request->file('files') as $idx => $file) {
            // get upload URL
            $up = Http::withHeaders(['Authorization' => $authToken])
                ->post("{$apiUrl}/b2api/v2/b2_get_upload_url", ['bucketId' => $bucketId])
                ->json();

            $uploadUrl       = $up['uploadUrl'];
            $uploadAuthToken = $up['authorizationToken'];

            $chapterSlug = 'chapter-'.$chapter->chapter_number;
            $remotePath  = "{$chapter->item_type}/{$mediaId}/{$chapterSlug}/{$file->getClientOriginalName()}";

            // upload to B2
            $resp = Http::withHeaders([
                    'Authorization'     => $uploadAuthToken,
                    'X-Bz-File-Name'    => rawurlencode($remotePath),
                    'Content-Type'      => $file->getClientMimeType(),
                    'X-Bz-Content-Sha1' => 'do_not_verify',
                ])
                ->withBody(fopen($file->getRealPath(), 'rb'), $file->getClientMimeType())
                ->post($uploadUrl)
                ->json();

            // record the page
            ChapterPage::create([
                'chapter_id'     => $chapter->id,
                'chapter_number' => $idx + 1,           // page 1, 2, 3...
                'file_path'      => $resp['fileName'],   // B2 path
            ]);
        }

        return response()->json([
            'message' => "Chapter {$chapter->chapter_number} uploaded!",
        ], 200);
    }
}
