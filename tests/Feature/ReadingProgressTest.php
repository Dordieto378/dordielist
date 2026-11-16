<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\Media;
use App\Models\ReadingProgress;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReadingProgressTest extends TestCase
{
    use DatabaseTransactions;

    public function test_progress_is_saved_for_each_volume_and_resumes_its_page_position_and_mode(): void
    {
        $user = $this->reader();
        [$media, $first] = $this->readerMedia();
        $second = $this->chapter($media, 2);
        $this->actingAs($user);
        $this->save($media, $first, 3, 'scroll', 0.425)->assertOk();
        $this->save($media, $second, 4, 'double')->assertOk();
        $this->save($media, $second, 4, 'double')->assertOk();

        $this->assertSame(2, ReadingProgress::where('user_id', $user->user_id)->count());
        $this->get($this->readerUrl($media, $first))
            ->assertOk()->assertViewHas('pageNumber', 3)
            ->assertViewHas('readerView', 'scroll')->assertViewHas('resumeScrollOffset', 0.425)
            ->assertDontSee('Bookmark this page')->assertDontSee('data-reader-bookmark-form');
        $this->get($this->readerUrl($media, $second))
            ->assertOk()->assertViewHas('pageNumber', 4)->assertViewHas('readerView', 'double');
        $this->get($this->readerUrl($media, $first, ['page' => 1, 'view' => 'one']))
            ->assertOk()->assertViewHas('pageNumber', 1)->assertViewHas('resumeScrollOffset', 0.0);
    }

    public function test_scrolling_page_50_opens_page_50_in_single_view_and_pages_50_and_51_in_double_view(): void
    {
        [$media, $chapter] = $this->readerMedia(55);
        $chapter->refresh();
        $this->actingAs($this->reader());
        $this->save($media, $chapter, 50, 'scroll', 0.25)->assertOk();
        $this->get($this->readerUrl($media, $chapter, ['view' => 'one']))
            ->assertOk()->assertViewHas('pageNumber', 50)->assertSee('alt="Page 50"', false);
        $this->get($this->readerUrl($media, $chapter, ['view' => 'double']))
            ->assertOk()->assertSee('alt="Page 50"', false)->assertSee('alt="Page 51"', false)
            ->assertDontSee('alt="Page 49"', false)
            ->assertSee($this->readerUrl($media, $chapter, ['page' => 52, 'view' => 'double', 'spread_start' => 50]));
        $this->get($this->readerUrl($media, $chapter, ['page' => 52, 'view' => 'double', 'spread_start' => 50]))
            ->assertOk()->assertSee('alt="Page 52"', false)->assertSee('alt="Page 53"', false)
            ->assertSee($this->readerUrl($media, $chapter, ['page' => 50, 'view' => 'double', 'spread_start' => 50]));
    }

    public function test_older_requests_cannot_overwrite_a_newer_location(): void
    {
        [$media, $chapter] = $this->readerMedia();
        $this->actingAs($this->reader());
        $this->save($media, $chapter, 4, 'scroll', 0.6, 2000)->assertOk();
        $this->save($media, $chapter, 2, 'one', 0, 1000)->assertOk();
        $this->get($this->readerUrl($media, $chapter))->assertOk()
            ->assertViewHas('pageNumber', 4)->assertViewHas('resumeScrollOffset', 0.6);
    }

    public function test_progress_is_private_and_rejects_invalid_pages_and_mismatched_chapters(): void
    {
        [$media, $chapter] = $this->readerMedia();
        $firstUser = $this->reader();
        $this->actingAs($firstUser);
        $this->save($media, $chapter, 3, 'one')->assertOk();
        $this->actingAs($this->reader());
        $this->get($this->readerUrl($media, $chapter))->assertOk()->assertViewHas('pageNumber', 1);
        $this->save($media, $chapter, 99, 'one')->assertNotFound();
        $this->save($media, $chapter, 1, 'scroll', 1.1)->assertUnprocessable();
        [$otherMedia] = $this->readerMedia();
        $this->save($otherMedia, $chapter, 1, 'one')->assertNotFound();
        $this->assertSame(1, ReadingProgress::where('user_id', $firstUser->user_id)->count());
    }

    public function test_start_reading_uses_the_latest_chapter_and_each_volume_link_can_resume_independently(): void
    {
        [$media, $first] = $this->readerMedia();
        $second = $this->chapter($media, 2);
        $this->actingAs($this->reader());
        $this->save($media, $second, 3, 'one', 0, 1000)->assertOk();
        $this->save($media, $first, 2, 'scroll', 0.3, 2000)->assertOk();
        $this->get(route('media.show', ['id' => $media->id]))->assertOk()
            ->assertSee($this->readerUrl($media, $first), false)
            ->assertSee($this->readerUrl($media, $second), false);
        $this->get($this->readerUrl($media, $first))->assertViewHas('pageNumber', 2);
        $this->get($this->readerUrl($media, $second))->assertViewHas('pageNumber', 3);
    }

    public function test_manhwa_and_light_novels_resume_their_supported_views(): void
    {
        $this->actingAs($this->reader());
        foreach (['manhwa' => 'scroll', 'light_novel' => 'double', 'doujin' => 'one'] as $type => $view) {
            [$media, $chapter] = $this->readerMedia(4, $type);
            $this->save($media, $chapter, 2, $view, 0.3)->assertOk();
            $this->get($this->readerUrl($media, $chapter))->assertOk()
                ->assertViewHas('pageNumber', 2)->assertViewHas('readerView', $view);
        }
    }

    private function save(Media $media, Chapter $chapter, int $page, string $view, float $offset = 0, ?int $recordedAt = null)
    {
        return $this->postJson(route('chapters.progress', ['media' => $media->id, 'chapter' => $chapter->id]), [
            'page_number' => $page, 'view' => $view, 'scroll_offset' => $offset,
            'spread_start' => $view === 'double' ? $page : null,
            'recorded_at' => $recordedAt ?? now()->getTimestampMs(),
        ]);
    }

    private function readerUrl(Media $media, Chapter $chapter, array $params = []): string
    {
        return route('chapters.page', array_merge(['media' => $media->id, 'chapter' => $chapter->chapter_number], $params));
    }

    private function reader(): User
    {
        $role = Role::where('role', 'Admin')->first() ?? Role::create(['role' => 'Admin']);
        return User::create([
            'username' => 'reader-progress-'.Str::lower(Str::random(10)),
            'email' => Str::lower(Str::random(12)).'@example.test', 'password' => 'password',
            'status' => 'active', 'role_id' => $role->role_id,
        ]);
    }

    private function readerMedia(int $pages = 4, string $type = 'manga'): array
    {
        $media = Media::create([
            'type' => $type, 'title_english' => 'Reader Progress '.Str::random(8),
            'slug' => 'reader-progress-'.Str::lower(Str::random(8)),
        ]);
        return [$media, $this->chapter($media, 1, $pages)];
    }

    private function chapter(Media $media, int $number, int $pages = 4): Chapter
    {
        $chapter = Chapter::create([
            'item_type' => $media->type, 'item_id' => $media->id, 'media_fk' => $media->id,
            'chapter_number' => $number, 'chapter_title' => 'Vol.'.$number,
        ]);
        for ($page = 1; $page <= $pages; $page++) {
            ChapterPage::create([
                'chapter_id' => $chapter->id, 'page_number' => $page,
                'file_path' => 'chapters/progress-test-'.$media->id.'/'.$number.'/'.$page.'.jpg',
            ]);
        }
        return $chapter;
    }
}
