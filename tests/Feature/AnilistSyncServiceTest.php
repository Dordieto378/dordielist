<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Services\AnilistSyncService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnilistSyncServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sync_imports_successful_entries_when_another_list_section_fails(): void
    {
        Media::where('source', 'anilist')->whereIn('source_id', [40443, 999999])->delete();

        $stale = Media::create([
            'type' => 'manga',
            'source' => 'anilist',
            'source_id' => 999999,
            'title_romaji' => 'Stale Local Entry',
            'slug' => 'stale-local-entry-'.Str::lower(Str::random(12)),
        ]);

        Http::fake(function (Request $request) {
            $data = $request->data();
            $query = (string) ($data['query'] ?? '');
            $variables = $data['variables'] ?? [];

            if (str_contains($query, 'Viewer')) {
                return Http::response(['data' => ['Viewer' => ['id' => 745004]]]);
            }

            if (str_contains($query, 'notifications')) {
                return Http::response([
                    'data' => [
                        'Page' => [
                            'pageInfo' => ['hasNextPage' => false],
                            'notifications' => [],
                        ],
                    ],
                ]);
            }

            if (($variables['type'] ?? null) === 'MANGA' && ($variables['status'] ?? null) === 'CURRENT') {
                return Http::response([
                    'data' => [
                        'MediaListCollection' => [
                            'lists' => [[
                                'entries' => [[
                                    'status' => 'CURRENT',
                                    'score' => 0,
                                    'progress' => 6,
                                    'startedAt' => ['year' => null, 'month' => null, 'day' => null],
                                    'completedAt' => ['year' => null, 'month' => null, 'day' => null],
                                    'media' => [
                                        'id' => 40443,
                                        'type' => 'MANGA',
                                        'format' => 'MANGA',
                                        'title' => [
                                            'english' => null,
                                            'romaji' => 'Bakuon Rettou',
                                            'native' => null,
                                        ],
                                        'coverImage' => ['extraLarge' => null],
                                        'bannerImage' => null,
                                        'description' => null,
                                        'genres' => [],
                                        'startDate' => ['year' => null, 'month' => null, 'day' => null],
                                        'countryOfOrigin' => 'JP',
                                        'status' => 'FINISHED',
                                        'averageScore' => 70,
                                        'tags' => [],
                                        'staff' => ['edges' => []],
                                        'episodes' => null,
                                        'chapters' => 105,
                                        'volumes' => 18,
                                    ],
                                ]],
                            ]],
                        ],
                    ],
                ]);
            }

            if (($variables['type'] ?? null) === 'MANGA' && ($variables['status'] ?? null) === 'PAUSED') {
                return Http::response([
                    'errors' => [[
                        'message' => 'Temporary AniList list failure.',
                    ]],
                ]);
            }

            return Http::response([
                'data' => [
                    'MediaListCollection' => [
                        'lists' => [],
                    ],
                ],
            ]);
        });

        $result = app(AnilistSyncService::class)->sync('fake-token');

        $this->assertTrue($result['partial']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['deleted']);
        $this->assertSame('MANGA', $result['list_failures'][0]['type']);
        $this->assertSame('PAUSED', $result['list_failures'][0]['status']);

        $this->assertDatabaseHas('media', [
            'source' => 'anilist',
            'source_id' => 40443,
            'type' => 'manga',
            'title_romaji' => 'Bakuon Rettou',
            'list_status' => 'CURRENT',
            'progress' => 6,
        ]);

        $this->assertDatabaseHas('media', [
            'id' => $stale->id,
            'source' => 'anilist',
            'source_id' => 999999,
        ]);
    }
}
