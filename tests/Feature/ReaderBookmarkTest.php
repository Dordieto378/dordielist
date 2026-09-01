<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReaderBookmarkTest extends TestCase
{
    use DatabaseTransactions;

    public function test_reader_bookmark_saves_current_page_and_resumes_chapter_without_page(): void
    {
        $user = $this->manager();
        [$media, $chapter] = $this->readerMedia();

        $this
            ->actingAs($user)
            ->get(route('chapters.page', [
                'media' => $media->id,
                'chapter' => $chapter->chapter_number,
                'page' => 3,
                'view' => 'one',
            ]))
            ->assertOk()
            ->assertSee('Bookmark this page')
            ->assertSee(route('chapters.bookmark', ['media' => $media->id, 'chapter' => $chapter->id]), false)
            ->assertSeeInOrder([
                'aria-label="Scroll view"',
                'aria-label="Single page view"',
                'aria-label="Double page view"',
                'aria-label="Bookmark this page"',
                'aria-label="Enter fullscreen"',
            ], false)
            ->assertDontSee('#facc15', false);

        $this
            ->actingAs($user)
            ->post(route('chapters.bookmark', ['media' => $media->id, 'chapter' => $chapter->id]), [
                'page_number' => 3,
                'view' => 'one',
            ])
            ->assertRedirect(route('chapters.page', [
                'media' => $media->id,
                'chapter' => $chapter->chapter_number,
                'page' => 3,
                'view' => 'one',
            ]));

        $this->assertDatabaseHas('reading_bookmarks', [
            'user_id' => $user->user_id,
            'media_id' => $media->id,
            'chapter_id' => $chapter->id,
            'page_number' => 3,
        ]);

        $this
            ->actingAs($user)
            ->get(route('chapters.page', [
                'media' => $media->id,
                'chapter' => $chapter->chapter_number,
                'view' => 'one',
            ]))
            ->assertOk()
            ->assertSee('Remove bookmark')
            ->assertSee('name="page_number" value="3"', false);

        $this
            ->actingAs($user)
            ->get(route('chapters.page', [
                'media' => $media->id,
                'chapter' => $chapter->chapter_number,
                'view' => 'double',
            ]))
            ->assertOk()
            ->assertSee('Remove bookmark')
            ->assertSee('name="page_number" value="3"', false);

        $this
            ->actingAs($user)
            ->postJson(route('chapters.bookmark', ['media' => $media->id, 'chapter' => $chapter->id]), [
                'page_number' => 3,
                'view' => 'one',
            ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'bookmarked' => false,
                'page_number' => 3,
            ]);

        $this->assertDatabaseMissing('reading_bookmarks', [
            'user_id' => $user->user_id,
            'media_id' => $media->id,
            'chapter_id' => $chapter->id,
            'page_number' => 3,
        ]);

        $this
            ->actingAs($user)
            ->get(route('chapters.page', [
                'media' => $media->id,
                'chapter' => $chapter->chapter_number,
                'view' => 'one',
            ]))
            ->assertOk()
            ->assertSee('Bookmark this page')
            ->assertSee('name="page_number" value="1"', false);

        $this
            ->actingAs($user)
            ->postJson(route('chapters.bookmark', ['media' => $media->id, 'chapter' => $chapter->id]), [
                'page_number' => 2,
                'view' => 'one',
            ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'bookmarked' => true,
                'page_number' => 2,
            ]);

        $this->assertDatabaseHas('reading_bookmarks', [
            'user_id' => $user->user_id,
            'media_id' => $media->id,
            'chapter_id' => $chapter->id,
            'page_number' => 2,
        ]);
        $this->assertDatabaseMissing('reading_bookmarks', [
            'user_id' => $user->user_id,
            'media_id' => $media->id,
            'chapter_id' => $chapter->id,
            'page_number' => 3,
        ]);
    }

    public function test_media_detail_start_reading_and_marked_volume_open_bookmarked_page(): void
    {
        $user = $this->manager();
        [$media, $firstChapter] = $this->readerMedia();
        $secondChapter = Chapter::create([
            'item_type' => 'manga',
            'item_id' => $media->id,
            'media_fk' => $media->id,
            'chapter_number' => 2,
            'chapter_title' => 'Vol.2',
        ]);
        for ($page = 1; $page <= 4; $page++) {
            ChapterPage::create([
                'chapter_id' => $secondChapter->id,
                'page_number' => $page,
                'file_path' => 'chapters/bookmark-test-'.$media->id.'/2/'.$page.'.jpg',
            ]);
        }

        $this
            ->actingAs($user)
            ->post(route('chapters.bookmark', ['media' => $media->id, 'chapter' => $secondChapter->id]), [
                'page_number' => 4,
                'view' => 'double',
            ])
            ->assertRedirect();

        $this
            ->actingAs($user)
            ->get(route('media.show', ['id' => $media->id]))
            ->assertOk()
            ->assertSee(route('chapters.page', [
                'media' => $media->id,
                'chapter' => $secondChapter->chapter_number,
                'page' => 4,
                'view' => 'one',
            ]), false)
            ->assertDontSee(route('chapters.page', [
                'media' => $media->id,
                'chapter' => $secondChapter->chapter_number,
                'page' => 1,
            ]), false);

        $this->assertSame(1, (int) $firstChapter->chapter_number);
    }

    private function manager(): User
    {
        $role = Role::where('role', 'Admin')->first()
            ?? Role::create(['role' => 'Admin']);

        return User::create([
            'username' => 'reader-bookmark-'.Str::lower(Str::random(10)),
            'email' => Str::lower(Str::random(12)).'@example.test',
            'password' => 'password',
            'status' => 'active',
            'role_id' => $role->role_id,
        ]);
    }

    private function readerMedia(): array
    {
        $media = Media::create([
            'type' => 'manga',
            'title_english' => 'Reader Bookmark '.Str::lower(Str::random(8)),
            'slug' => 'reader-bookmark-'.Str::lower(Str::random(8)),
        ]);

        $chapter = Chapter::create([
            'item_type' => 'manga',
            'item_id' => $media->id,
            'media_fk' => $media->id,
            'chapter_number' => 1,
            'chapter_title' => 'Vol.1',
        ]);

        for ($page = 1; $page <= 4; $page++) {
            ChapterPage::create([
                'chapter_id' => $chapter->id,
                'page_number' => $page,
                'file_path' => 'chapters/bookmark-test-'.$media->id.'/1/'.$page.'.jpg',
            ]);
        }

        return [$media, $chapter];
    }
}
