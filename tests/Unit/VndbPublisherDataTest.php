<?php

namespace Tests\Unit;

use App\Support\VndbPublisherData;
use PHPUnit\Framework\TestCase;

class VndbPublisherDataTest extends TestCase
{
    public function test_groups_release_publishers_by_vn_and_release_language(): void
    {
        $grouped = VndbPublisherData::groupByVn([
            [
                'vns' => [
                    ['id' => 'v17'],
                ],
                'languages' => [
                    ['lang' => 'en'],
                    ['lang' => 'ja'],
                ],
                'producers' => [
                    [
                        'id' => 'p42',
                        'name' => 'MangaGamer',
                        'lang' => 'en',
                        'publisher' => true,
                    ],
                    [
                        'id' => 'p99',
                        'name' => 'Developer Only',
                        'lang' => 'ja',
                        'publisher' => false,
                    ],
                ],
            ],
        ]);

        $this->assertSame([
            17 => [
                [
                    'name' => 'MangaGamer',
                    'source_id' => 42,
                    'language' => 'en',
                ],
                [
                    'name' => 'MangaGamer',
                    'source_id' => 42,
                    'language' => 'ja',
                ],
            ],
        ], $grouped);
    }
}
