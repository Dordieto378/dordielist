<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Models\Doujin;
use App\Models\DoujinPage;
use Illuminate\Support\Facades\Http;

set_time_limit(0);
class SettingsController extends Controller
{
    public function edit()
    {
        $user = Auth::user();
        return view('settings.account', compact('user'));
    }

    public function update(Request $request)
    {
        $user = Auth::user();

        $rules = [
            'username' => [
                'required',
                'string',
                'max:255',
                Rule::unique('users', 'username')->ignore($user->user_id, 'user_id'),
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->user_id, 'user_id'),
            ],
            'password' => [
                'nullable',
                'string',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{15,}$/',
                'confirmed',
            ],
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            $firstError = $validator->errors()->first();

            return back()
                ->with('status', $firstError)
                ->with('status_color', 'red');
        }

        $data = $validator->validated();

        $user->username = $data['username'];
        $user->email    = $data['email'];

        if (!empty($data['password'])) {
            $user->password = $data['password'];
        }

        $user->save();

        return redirect()
            ->route('settings.profile.edit')
            ->with('status', 'Profile updated successfully.')
            ->with('status_color', 'green');
    }

    public function users()
    {
        $users = User::with('role')->get();

        return view('settings.users', compact('users'));
    }

    public function addDoujin()
    {
        $authors = Doujin::distinct()
                         ->orderBy('author_name')
                         ->pluck('author_name');

        return view('settings.addDoujin', compact('authors'));
    }

   public function storeDoujin(Request $request)
    {
        $request->validate([
            'title'   => ['required', 'string', 'max:255'],
            'author'  => ['required', 'string', 'max:255'],
            'files'   => ['required', 'array', 'min:1'],
            'files.*' => ['file', 'image', 'mimes:png,jpeg,jpg,webp,gif'],
        ]);

        $authorRaw = trim($request->input('author'));
        $titleRaw  = trim($request->input('title'));

        $baseFolder = "doujins/{$authorRaw}/{$titleRaw}";

        $doujin = Doujin::create([
            'author_name' => $request->input('author'),
            'doujin_name' => $request->input('title'),
            'folder'      => $baseFolder,
            'cover_url'   => '',
        ]);

        $accountId      = env('B2_KEY_ID');
        $applicationKey = env('B2_APP_KEY');
        $bucketName     = env('B2_BUCKET');

        $authResp = Http::withBasicAuth($accountId, $applicationKey)
            ->get("https://api.backblazeb2.com/b2api/v2/b2_authorize_account");

        if ($authResp->failed()) {
            return back()
                ->with('status', 'B2 authorization failed: ' . $authResp->body())
                ->with('status_color', 'red');
        }

        $authData  = $authResp->json();
        $authToken = $authData['authorizationToken'];
        $apiUrl    = $authData['apiUrl'];

        // 6) List buckets to get bucketId
        $listBucketsResp = Http::withHeaders([
            'Authorization' => $authToken,
        ])->post("{$apiUrl}/b2api/v2/b2_list_buckets", [
            'accountId'  => $accountId,
            'bucketName' => $bucketName,
            'bucketId'   => null,
        ]);

        if ($listBucketsResp->failed()) {
            return back()
                ->with('status', 'B2 list_buckets failed: ' . $listBucketsResp->body())
                ->with('status_color', 'red');
        }

        $buckets = $listBucketsResp->json()['buckets'] ?? [];
        if (empty($buckets)) {
            return back()
                ->with('status', "Bucket '{$bucketName}' not found in B2.")
                ->with('status_color', 'red');
        }

        $bucketId = $buckets[0]['bucketId'];

        $uploadedFiles = $request->file('files');
        $pageNumber    = 1;
        $firstPathSet  = false;

        // 7) Upload each file one by one
        foreach ($uploadedFiles as $file) {
            $originalName = $file->getClientOriginalName();
            $remotePath   = "{$baseFolder}/{$originalName}";

            // 7a) Get an upload URL for this bucket
            $uploadUrlResp = Http::withHeaders([
                'Authorization' => $authToken,
            ])->post("{$apiUrl}/b2api/v2/b2_get_upload_url", [
                'bucketId' => $bucketId,
            ]);

            if ($uploadUrlResp->failed()) {
                return back()
                    ->with('status', 'Failed to get B2 upload URL: ' . $uploadUrlResp->body())
                    ->with('status_color', 'red');
            }

            $uploadData      = $uploadUrlResp->json();
            $uploadUrl       = $uploadData['uploadUrl'];
            $uploadAuthToken = $uploadData['authorizationToken'];

            // 7b) Open local file for reading
            $localPath = $file->getRealPath();
            if (! $localPath || ! file_exists($localPath)) {
                return back()
                    ->with('status', "File '{$originalName}' is not readable.")
                    ->with('status_color', 'red');
            }

            $fileStream = fopen($localPath, 'rb');
            if (! $fileStream) {
                return back()
                    ->with('status', "Cannot open '{$originalName}' for upload.")
                    ->with('status_color', 'red');
            }

            // 7c) Upload the file
            $uploadAttempt = Http::withHeaders([
                'Authorization'     => $uploadAuthToken,
                'X-Bz-File-Name'    => rawurlencode($remotePath),
                'Content-Type'      => $file->getClientMimeType(),
                'X-Bz-Content-Sha1' => 'do_not_verify',
            ])->withBody(fopen($localPath, 'rb'), $file->getClientMimeType())
              ->post($uploadUrl);

            fclose($fileStream);

            if ($uploadAttempt->failed()) {
                return back()
                    ->with('status', "Upload failed for '{$originalName}': " . $uploadAttempt->body())
                    ->with('status_color', 'red');
            }

            $uploadResult = $uploadAttempt->json();
            $savedPath    = $uploadResult['fileName'];
            // e.g. "doujins/my-title-slug/1.webp"

            // 7d) If this is the first file, set as cover_url
            if (! $firstPathSet) {
                $doujin->cover_url = $savedPath;
                $doujin->save();
                $firstPathSet = true;
            }

            // 7e) Insert DoujinPage record
            DoujinPage::create([
                'doujin_id'   => $doujin->id,
                'page_number' => $pageNumber,
                'file_path'   => $savedPath,
            ]);

            $pageNumber++;
        }

        // 8) Redirect back with success message
        return redirect()
            ->route('settings.addDoujin')
            ->with([
                'status'       => 'Doujin uploaded successfully!',
                'status_color' => 'green',
        ]);
    }

    protected function deleteTitleFolder(string $author, string $title): bool
    {
        // -------------------------------
        // 1) Authorize with B2
        // -------------------------------
        $accountId      = env('B2_KEY_ID');
        $applicationKey = env('B2_APP_KEY');

        $authResp = Http::withBasicAuth($accountId, $applicationKey)
            ->get('https://api.backblazeb2.com/b2api/v2/b2_authorize_account');

        if ($authResp->failed()) {
            // If authorization fails, throw or handle accordingly
            throw new \Exception('B2 authorize failed: ' . $authResp->body());
        }

        $authData  = $authResp->json();
        $authToken = $authData['authorizationToken']; // used for subsequent calls
        $apiUrl    = $authData['apiUrl'];             // e.g. "https://api003.backblazeb2.com"

        // -------------------------------
        // 2) Find the bucketId for your bucket name
        // -------------------------------
        $bucketName = env('B2_BUCKET'); // e.g. "Dordielist"

        $listBucketsResp = Http::withHeaders([
            'Authorization' => $authToken,
        ])->post("{$apiUrl}/b2api/v2/b2_list_buckets", [
            'accountId'  => $accountId,
            'bucketName' => $bucketName,
            'bucketId'   => null,
        ]);

        if ($listBucketsResp->failed()) {
            throw new \Exception('b2_list_buckets failed: ' . $listBucketsResp->body());
        }

        $buckets = $listBucketsResp->json()['buckets'] ?? [];
        if (empty($buckets)) {
            // No such bucket found
            throw new \Exception("Bucket '{$bucketName}' not found in B2.");
        }

        $bucketId = $buckets[0]['bucketId'];

        // -------------------------------
        // 3) Build the prefix string
        // -------------------------------
        // We include a trailing slash so we only match files under that “folder”
        $oldPrefix = "doujins/{$author}/{$title}/";

        // -------------------------------
        // 4) Paginate through all files under oldPrefix
        // -------------------------------
        $nextFileName = null;

        do {
            $listBody = [
                'bucketId'      => $bucketId,
                'prefix'        => $oldPrefix,
                'maxFileCount'  => 1000,
            ];

            if ($nextFileName !== null) {
                $listBody['startFileName'] = $nextFileName;
            }

            $listResp = Http::withHeaders([
                'Authorization' => $authToken,
            ])->post("{$apiUrl}/b2api/v2/b2_list_file_names", $listBody);

            if ($listResp->failed()) {
                throw new \Exception('b2_list_file_names failed: ' . $listResp->body());
            }

            $listJson = $listResp->json();
            $files    = $listJson['files'] ?? [];

            // Delete each file in that prefix:
            foreach ($files as $entry) {
                $fileName = $entry['fileName']; // full key, e.g. "doujins/Drachef/OldTitle/1.webp"
                $fileId   = $entry['fileId'];

                // Call b2_delete_file_version for this file:
                $delResp = Http::withHeaders([
                    'Authorization' => $authToken,
                ])->post("{$apiUrl}/b2api/v2/b2_delete_file_version", [
                    'fileName' => $fileName,
                    'fileId'   => $fileId,
                ]);

                if ($delResp->failed()) {
                    // If any delete fails, you can log or throw—as needed.
                    throw new \Exception("b2_delete_file_version failed for {$fileName}: " . $delResp->body());
                }
            }

            // If there’s a nextFileName, keep looping:
            $nextFileName = $listJson['nextFileName'] ?? null;
        } while ($nextFileName !== null);

        // Now all objects under "doujins/{$author}/{$title}/" are deleted.
        // There is no “empty folder” to clean up explicitly.

        return true;
    }

    protected function renameTitleFolder(string $author, string $oldTitle, string $newTitle): void
    {
        // -------------------------------
        // 1) B2 Authorize Account
        // -------------------------------
        $accountId      = env('B2_KEY_ID');
        $applicationKey = env('B2_APP_KEY');

        $authResp = Http::withBasicAuth($accountId, $applicationKey)
            ->get('https://api.backblazeb2.com/b2api/v2/b2_authorize_account');

        if ($authResp->failed()) {
            throw new \Exception('B2 auth failed: ' . $authResp->body());
        }

        $authData       = $authResp->json();
        $authToken      = $authData['authorizationToken'];   // we'll use this in subsequent calls
        $apiUrl         = $authData['apiUrl'];               // e.g. "https://api003.backblazeb2.com"
        $downloadUrl    = $authData['downloadUrl'];          // not needed for listing/copy, but good to know

        // -------------------------------
        // 2) Find the bucketId for your bucket name
        // -------------------------------
        $bucketName = env('B2_BUCKET'); // your bucket exactly as it is (capital‐D is fine here)
        $listBucketsResp = Http::withHeaders([
            'Authorization' => $authToken,
        ])->post("{$apiUrl}/b2api/v2/b2_list_buckets", [
            'accountId'  => $accountId,
            'bucketName' => $bucketName,
            'bucketId'   => null,
        ]);

        if ($listBucketsResp->failed()) {
            throw new \Exception('b2_list_buckets failed: ' . $listBucketsResp->body());
        }

        $buckets = $listBucketsResp->json()['buckets'] ?? [];
        if (empty($buckets)) {
            throw new \Exception("Bucket '{$bucketName}' not found.");
        }

        // We assume only one bucket with this exact name:
        $bucketId = $buckets[0]['bucketId'];

        // -------------------------------
        // 3) Prepare the old & new prefixes
        // -------------------------------
        // (1) The “old prefix” in B2 – exactly how your files are organized in B2:
        $oldPrefix = "doujins/{$author}/{$oldTitle}";
        // (2) We will copy everything under “$oldPrefix/…” to this new prefix:
        $newPrefix = "doujins/{$author}/{$newTitle}";

        //
        // Note: We will list all files whose fileName begins with "$oldPrefix/", then
        //       for each returned entry (fileName + fileId), do a copy → delete.
        //

        // -------------------------------
        // 4) Paginated loop: List all files under oldPrefix
        // -------------------------------
        $nextFileName = null;

        do {
            $listBody = [
                'bucketId'      => $bucketId,
                'prefix'        => $oldPrefix . '/', // include trailing slash so we only get that folder
                'maxFileCount'  => 1000,             // max is 1000 per call; use pagination if >1000
            ];

            if ($nextFileName !== null) {
                // if there's a nextFileName from a previous page, send it along:
                $listBody['startFileName'] = $nextFileName;
            }

            $listResp = Http::withHeaders([
                'Authorization' => $authToken,
            ])->post("{$apiUrl}/b2api/v2/b2_list_file_names", $listBody);

            if ($listResp->failed()) {
                throw new \Exception('b2_list_file_names failed: ' . $listResp->body());
            }

            $listJson = $listResp->json();
            $files    = $listJson['files'] ?? [];
            // The API returns an array of objects with keys: fileName, fileId, size, uploadTimestamp, etc.

            // For each file returned under oldPrefix:
            foreach ($files as $fileEntry) {
                $oldFileName = $fileEntry['fileName']; // e.g. "doujins/Drachef/OldTitle/001.jpg"
                $oldFileId   = $fileEntry['fileId'];   // needed for deletion later

                // Compute the new file name by replacing the prefix:
                //   e.g. "doujins/Drachef/NewTitle/001.jpg"
                $relativePath = Str::after($oldFileName, "{$oldPrefix}/");
                $newFileName  = "{$newPrefix}/{$relativePath}";

                // -------------------------------
                // 5) Copy this file to the new path
                // -------------------------------
                $copyResp = Http::withHeaders([
                    'Authorization' => $authToken,
                ])->post("{$apiUrl}/b2api/v2/b2_copy_file", [
                    'sourceFileId'       => $oldFileId,
                    'fileName'           => $newFileName,
                    'destinationBucketId'=> $bucketId,
                ]);

                if ($copyResp->failed()) {
                    // If copy fails, you can decide whether to bail out or log & continue
                    throw new \Exception("b2_copy_file failed for {$oldFileName} → {$newFileName}: " . $copyResp->body());
                }

                // We do NOT delete the old file yet; we wait until all copies are done
                // or we could delete one-by-one right after copying, depending on your preference.
                // For clarity, let's delete it immediately after copying:

                $delResp = Http::withHeaders([
                    'Authorization' => $authToken,
                ])->post("{$apiUrl}/b2api/v2/b2_delete_file_version", [
                    'fileName' => $oldFileName,
                    'fileId'   => $oldFileId,
                ]);

                if ($delResp->failed()) {
                    // If delete fails, you now have duplicate files (old + new) in B2.
                    // You can choose to log this and continue, or throw. Let's throw:
                    throw new \Exception("b2_delete_file_version failed for {$oldFileName}: " . $delResp->body());
                }
            }

            // If the response has “nextFileName,” we need to continue listing:
            $nextFileName = $listJson['nextFileName'] ?? null;
        } while ($nextFileName !== null);

        //
        // At this point, every file under "doujins/{$author}/{$oldTitle}/" has been:
        //   1) Copied to "doujins/{$author}/{$newTitle}/…"
        //   2) Deleted under the old prefix
        //
        // Backblaze B2 does not maintain “folders” in the usual sense—once all files under
        // that prefix are removed, there is no need to explicitly delete an “empty folder.”
        //
        // From here, you can proceed to update your database (bundle record, cover_url, page rows, etc.).
    }
    public function destroy(string $author, string $title)
    {
        // (1) Delete from B2:
        $this->deleteTitleFolder($author, $title);

        // (2) Delete DB records (pages + doujin itself):
        $doujin = Doujin::where('author_name', $author)
                        ->where('doujin_name', $title)
                        ->firstOrFail();

        DoujinPage::where('doujin_id', $doujin->id)->delete();
        $doujin->delete();

        return redirect()
            ->route('category', ['category' => 'DOUJINS'])
            ->with('status', "Deleted “{$title}” by {$author}.");
    }

    public function rename(Request $request, string $author, string $oldTitle)
    {
        $request->validate([
            'newTitle' => ['required','string','max:255'],
        ]);

        $newTitle = trim($request->input('newTitle'));

        // (1) Rename prefix in B2:
        $this->renameTitleFolder($author, $oldTitle, $newTitle);

        // (2) Update DB: folder, doujin_name, cover_url, page file_paths
        $doujin = Doujin::where('author_name', $author)
                        ->where('doujin_name', $oldTitle)
                        ->firstOrFail();

        $oldFolder = $doujin->folder;               // "doujins/Author/OldTitle"
        $newFolder = "{$author}/{$newTitle}";

        $doujin->doujin_name = $newTitle;
        $doujin->folder      = $newFolder;
        // adjust cover_url if it used the old prefix
        $doujin->cover_url = Str::replaceFirst("{$oldFolder}/", "{$newFolder}/", $doujin->cover_url);
        $doujin->save();

        DoujinPage::where('doujin_id', $doujin->id)
            ->get()
            ->each(function($page) use ($oldFolder, $newFolder) {
                $page->file_path = Str::replaceFirst("{$oldFolder}/", "{$newFolder}/", $page->file_path);
                $page->save();
            });

        return redirect()
            ->route('media.doujin', ['doujin' => $doujin->id])
            ->with('status', "Renamed “{$oldTitle}” → “{$newTitle}”.");
    }
}
