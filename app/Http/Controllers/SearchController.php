<?php

namespace App\Http\Controllers;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SearchController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q'));
        if ($q === '') {
            return response()->json([]);
        }

        $limit = (int) $request->query('limit', 12);
        $mediaLimit = max(1, min($limit, 10));
        $metadataLimit = max(1, min($limit, 12));

        $mediaResults = Media::query()
            ->where(function ($w) use ($q) {
                $w->where('title_english', 'like', "%{$q}%")
                    ->orWhere('title_romaji',  'like', "%{$q}%")
                    ->orWhere('title_native',  'like', "%{$q}%");
            })
            ->orderByDesc('id')
            ->limit($mediaLimit)
            ->get()
            ->map(fn (Media $m) => $this->mapMediaResult($m));

        $metadataResults = collect()
            ->merge($this->searchMetadata(
                $q,
                'anilist_tags',
                'anilist_item_tag',
                'tag_id',
                'tags',
                'Tag',
                $metadataLimit,
                ['anime', 'hentai', 'manga', 'manwha']
            ))
            ->merge($this->searchMetadata(
                $q,
                'vn_tags',
                'vn_item_tag',
                'tag_id',
                'tags',
                'Tag',
                $metadataLimit,
                ['vn']
            ))
            ->merge($this->searchMetadata(
                $q,
                'anilist_genres',
                'anilist_item_genre',
                'genre_id',
                'genre',
                'Genre',
                $metadataLimit,
                ['anime', 'hentai', 'manga', 'manwha']
            ))
            ->merge($this->searchMetadata(
                $q,
                'anilist_studios',
                'anilist_item_studio',
                'studio_id',
                'studio',
                'Studio',
                $metadataLimit,
                ['anime', 'hentai']
            ))
            ->merge($this->searchMetadata(
                $q,
                'anilist_authors',
                'anilist_item_author',
                'author_id',
                'author',
                'Author',
                $metadataLimit,
                ['manga', 'manwha']
            ))
            ->merge($this->searchMetadata(
                $q,
                'doujin_authors',
                'doujin_item_author',
                'author_id',
                'author',
                'Author',
                $metadataLimit,
                ['doujin']
            ))
            ->merge($this->searchMetadata(
                $q,
                'vn_developers',
                'vn_item_developer',
                'developer_id',
                'developers',
                'Developer',
                $metadataLimit,
                ['vn']
            ))
            ->sortBy([
                ['sort_rank', 'asc'],
                ['label', 'asc'],
                ['subtitle', 'asc'],
            ])
            ->values();

        $results = collect();
        $mediaQueue = $mediaResults->values();
        $metadataQueue = $metadataResults->values();

        while ($results->count() < $limit && ($mediaQueue->isNotEmpty() || $metadataQueue->isNotEmpty())) {
            if ($mediaQueue->isNotEmpty()) {
                $results->push($mediaQueue->shift());
            }

            if ($results->count() >= $limit) {
                break;
            }

            if ($metadataQueue->isNotEmpty()) {
                $results->push($metadataQueue->shift());
            }
        }

        return response()->json(
            $results->map(function (array $item) {
                unset($item['sort_rank']);

                return $item;
            })->values()
        );
    }

    private function mapMediaResult(Media $media): array
    {
        $typeConfig = $this->typeConfig($media->type);
        $cover = $media->cover_url;
        if ($cover) {
            if (!preg_match('#^https?://#i', $cover) && !str_starts_with($cover, '/')) {
                $cover = Storage::url(ltrim($cover, '/'));
            }
        } else {
            $cover = asset('images/no-image.jpg');
        }

        $isNsfw = (int) ($media->isNsfw ?? 0) === 1;

        return [
            'id' => (string) $media->id,
            'type' => $typeConfig['type'],
            'title' => [
                'english' => $media->title_english ?: null,
                'romaji' => $media->title_romaji ?: null,
                'native' => $media->title_native ?: null,
            ],
            'label' => $media->title_english ?: ($media->title_romaji ?: ($media->title_native ?: 'No Title')),
            'subtitle' => $typeConfig['label'],
            'url' => $this->mediaUrl($media, $typeConfig['type']),
            'result_kind' => 'media',
            'thumbnail_label' => null,
            'sort_rank' => 0,
            'cover' => $cover,
            'coverImage' => ['extraLarge' => $cover],
            'isNsfw' => $isNsfw,
            'isAdult' => $isNsfw,
        ];
    }

    private function searchMetadata(
        string $query,
        string $entityTable,
        string $pivotTable,
        string $pivotKey,
        string $filterKey,
        string $kindLabel,
        int $limit,
        array $mediaTypes
    ) {
        $rows = DB::table("{$entityTable} as entity")
            ->join("{$pivotTable} as pivot", "pivot.{$pivotKey}", '=', 'entity.id')
            ->join('media', 'media.id', '=', 'pivot.media_id')
            ->whereIn('media.type', $mediaTypes)
            ->where('entity.name', 'like', "%{$query}%")
            ->select('entity.name', 'media.type')
            ->groupBy('entity.name', 'media.type')
            ->orderByRaw('CASE WHEN entity.name LIKE ? THEN 0 ELSE 1 END', [$query.'%'])
            ->orderBy('entity.name')
            ->limit($limit)
            ->get();

        return $rows->map(function ($row) use ($filterKey, $kindLabel) {
            $typeConfig = $this->typeConfig($row->type);

            return [
                'id' => md5($kindLabel.'|'.$filterKey.'|'.$row->type.'|'.$row->name),
                'type' => $typeConfig['type'],
                'title' => [
                    'english' => $row->name,
                    'romaji' => null,
                    'native' => null,
                ],
                'label' => $row->name,
                'subtitle' => "{$kindLabel} - {$typeConfig['label']}",
                'url' => category_filter_url($typeConfig['category'], $filterKey, $row->name),
                'result_kind' => 'filter',
                'thumbnail_label' => strtoupper(substr($kindLabel, 0, 1)),
                'sort_rank' => 1,
                'cover' => null,
                'coverImage' => ['extraLarge' => null],
                'isNsfw' => false,
                'isAdult' => false,
            ];
        });
    }

    private function mediaUrl(Media $media, string $type): string
    {
        return match ($type) {
            'visual-novel' => url("/vn/{$media->id}"),
            'doujin' => url("/doujin/{$media->id}"),
            default => url("/media/{$media->id}"),
        };
    }

    private function typeConfig(?string $type): array
    {
        return match (strtolower((string) $type)) {
            'anime' => ['type' => 'anime', 'label' => 'Anime', 'category' => 'animes'],
            'hentai' => ['type' => 'hentai', 'label' => 'Hentai', 'category' => 'hentais'],
            'manga' => ['type' => 'manga', 'label' => 'Manga', 'category' => 'mangas'],
            'manwha' => ['type' => 'manwha', 'label' => 'Manwha', 'category' => 'manwhas'],
            'vn' => ['type' => 'visual-novel', 'label' => 'Visual Novel', 'category' => 'visual-novel'],
            'doujin' => ['type' => 'doujin', 'label' => 'Doujin', 'category' => 'doujins'],
            default => ['type' => strtolower((string) $type), 'label' => ucfirst((string) $type), 'category' => strtolower((string) $type)],
        };
    }
}
