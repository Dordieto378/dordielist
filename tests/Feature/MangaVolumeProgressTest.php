<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MangaVolumeProgressTest extends TestCase
{
    use DatabaseTransactions;

    private bool $aniListUnavailable = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['graphql.anilist.co' => fn () => $this->aniListUnavailable
            ? Http::response(['errors' => [['message' => 'AniList unavailable.']]], 503)
            : Http::response(['data' => ['SaveMediaListEntry' => ['id' => 123]]])]);
        $role = Role::where('role', 'Admin')->first() ?? Role::create(['role' => 'Admin']);
        $this->actingAs(User::create([
            'username' => 'manga-volume-'.Str::lower(Str::random(10)),
            'email' => Str::lower(Str::random(12)).'@example.test',
            'password' => 'password', 'status' => 'active', 'role_id' => $role->role_id,
            'anilist_access_token' => 'fake-anilist-token',
        ]));
    }

    public function test_manga_edit_form_shows_saved_volume_progress_alongside_chapter_progress(): void
    {
        $media = $this->media();
        $response = $this->get(route('media.show', $media->id))->assertOk();
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent(), LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        $volume = $xpath->query('//form[@id="editEntryForm"]//input[@name="progress_volumes"]');
        $this->assertCount(1, $volume);
        $this->assertSame('2', $volume->item(0)->getAttribute('value'));
        $this->assertSame('10', $volume->item(0)->getAttribute('max'));
        $this->assertSame('0', $volume->item(0)->getAttribute('min'));
        $this->assertSame('20', $xpath->query('//form[@id="editEntryForm"]//input[@name="progress"]')->item(0)->getAttribute('value'));
        foreach (['anime', 'manhwa', 'light_novel'] as $type) {
            $other = $this->media($type);
            $this->get(route('media.show', $other->id))->assertOk()->assertDontSee('name="progress_volumes"', false);
        }
    }

    public function test_edit_saves_volume_and_chapter_counts_independently_and_sends_them_to_anilist(): void
    {
        $media = $this->media();
        $this->update($media, ['progress' => 30, 'progress_volumes' => 4])
            ->assertRedirect(route('media.show', $media->id))->assertSessionMissing('entry_update_error');
        $this->assertDatabaseHas('media', ['id' => $media->id, 'progress' => 30, 'progress_volumes' => 4]);
        Http::assertSent(fn ($request) => $request['variables']['progress'] === 30
            && $request['variables']['progressVolumes'] === 4
            && str_contains($request['query'], 'progressVolumes: $progressVolumes'));
        $this->get(route('media.show', $media->id))->assertOk()
            ->assertViewHas('item', fn ($item) => (int) $item['userProgressVolumes'] === 4);
    }

    public function test_invalid_volume_progress_is_rejected_without_updating_the_entry(): void
    {
        $media = $this->media();
        foreach ([-1, 1.5, 'invalid', 11] as $invalid) {
            $this->update($media, ['progress_volumes' => $invalid])
                ->assertRedirect()->assertSessionHas('open_edit_entry_modal', true)
                ->assertSessionHas('entry_update_error')->assertSessionHasInput('progress_volumes', $invalid);
            $this->assertDatabaseHas('media', ['id' => $media->id, 'progress_volumes' => 2]);
        }
        Http::assertNothingSent();
    }

    public function test_zero_blank_and_unknown_total_volume_progress_are_supported(): void
    {
        $media = $this->media();
        $media->update(['volumes_cnt' => null]);
        foreach ([0, 50, ''] as $value) {
            $this->update($media, ['progress_volumes' => $value])->assertRedirect()->assertSessionMissing('entry_update_error');
            $this->assertDatabaseHas('media', ['id' => $media->id, 'progress_volumes' => $value === '' ? null : $value]);
        }
        Http::assertSent(fn ($request) => array_key_exists('progressVolumes', $request['variables'])
            && $request['variables']['progressVolumes'] === null);
        Http::assertSentCount(3);
    }

    public function test_omitting_volume_progress_preserves_it_and_other_media_cannot_update_it(): void
    {
        $manga = $this->media();
        $this->update($manga)->assertRedirect()->assertSessionMissing('entry_update_error');
        $this->assertDatabaseHas('media', ['id' => $manga->id, 'progress_volumes' => 2]);
        $anime = $this->media('anime');
        $this->update($anime, ['progress_volumes' => 'not applicable'])->assertRedirect()->assertSessionMissing('entry_update_error');
        $this->assertDatabaseHas('media', ['id' => $anime->id, 'progress_volumes' => 2]);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => array_key_exists('progressVolumes', $request['variables']));
    }

    public function test_anilist_failure_keeps_the_saved_volume_count_and_restores_the_submitted_value(): void
    {
        $media = $this->media();
        $this->aniListUnavailable = true;
        $this->update($media, ['progress_volumes' => 4])->assertRedirect()
            ->assertSessionHas('entry_update_error', 'AniList unavailable.')
            ->assertSessionHasInput('progress_volumes', 4);
        $this->assertDatabaseHas('media', ['id' => $media->id, 'progress_volumes' => 2]);
    }

    private function update(Media $media, array $data = [])
    {
        return $this->from(route('media.show', $media->id))->patch(route('media.entry.update', $media), array_merge([
            'progress' => 20, 'list_status' => 'CURRENT', 'user_score' => 75,
            'list_start_date' => null, 'list_end_date' => null,
        ], $data));
    }

    private function media(string $type = 'manga'): Media
    {
        return Media::create([
            'type' => $type, 'source' => 'anilist', 'source_id' => random_int(80000000, 89999999),
            'title_english' => 'Volume Progress Test', 'slug' => 'volume-progress-'.Str::lower(Str::random(12)),
            'chapters_cnt' => 100, 'episodes_cnt' => 100, 'volumes_cnt' => 10,
            'progress' => 20, 'progress_volumes' => 2, 'list_status' => 'CURRENT',
        ]);
    }
}
