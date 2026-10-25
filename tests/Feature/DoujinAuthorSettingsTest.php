<?php

namespace Tests\Feature;

use App\Models\DoujinAuthor;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DoujinAuthorSettingsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_author_social_links_can_be_updated_from_settings(): void
    {
        $user = $this->manager();
        $author = $this->author('Settings Artist');
        $hiddenAuthor = $this->author('Hidden Social Artist');
        $media = $this->doujin('Settings Doujin');
        $media->doujinAuthors()->attach($author->id);
        $hiddenAuthor->forceFill([
            'twitter_url' => 'https://twitter.example/current',
        ])->save();

        $this
            ->actingAs($user)
            ->get(route('settings.doujin-authors'))
            ->assertOk()
            ->assertSee('Settings Artist')
            ->assertSee('name="twitter_url[]"', false)
            ->assertSee('name="patreon_url[]"', false)
            ->assertSee('name="fanbox_url[]"', false)
            ->assertSee('name="pixiv_url[]"', false)
            ->assertSee('placeholder="Patreon URL"', false)
            ->assertSee('Save')
            ->assertSee('Hidden Social Artist')
            ->assertSee('https://twitter.example/current')
            ->assertDontSee('id="editArtistModal"', false)
            ->assertSee('id="doujinArtistSearch"', false)
            ->assertSee('data-add-social', false)
            ->assertSee('data-remove-social', false)
            ->assertDontSee('Add Existing Artist')
            ->assertDontSee('No social links');

        $this
            ->actingAs($user)
            ->put(route('settings.doujin-authors.update', $author), [
                'name' => 'Settings Artist Updated',
                'twitter_url' => "https://twitter.example/artist\nhttps://x.example/artist",
                'patreon_url' => '',
                'fanbox_url' => 'https://fanbox.example/artist',
                'pixiv_url' => 'https://pixiv.example/users/123',
            ])
            ->assertRedirect(route('settings.doujin-authors'));

        $author->refresh();

        $this->assertSame('Settings Artist Updated', $author->name);
        $this->assertSame("https://twitter.example/artist\nhttps://x.example/artist", $author->twitter_url);
        $this->assertNull($author->patreon_url);
        $this->assertSame('https://fanbox.example/artist', $author->fanbox_url);
        $this->assertSame('https://pixiv.example/users/123', $author->pixiv_url);

        $this
            ->actingAs($user)
            ->get(route('settings.doujin-authors'))
            ->assertOk()
            ->assertSee('Settings Artist Updated');
    }

    public function test_failed_artist_update_keeps_the_entered_links_in_the_inline_form(): void
    {
        $author = $this->author('Artist With Failed Edit');
        $other = $this->author('Existing Artist Name');
        $this->actingAs($this->manager())
            ->put(route('settings.doujin-authors.update', $author), [
                'name' => $other->name,
                'twitter_url' => ['https://example.test/new-social', 'https://example.test/second-social'],
                'patreon_url' => [''],
            ])
            ->assertRedirect(route('settings.doujin-authors'))
            ->assertSessionHas('open_edit_author_id', $author->id);

        $response = $this->get(route('settings.doujin-authors'))->assertOk();
        $document = new \DOMDocument();
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $form = $document->getElementById('artist-form-'.$author->id);
        $twitter = $xpath->query('.//input[@name="twitter_url[]"]', $form);
        $this->assertSame(2, $twitter->length);
        $this->assertSame('https://example.test/new-social', $twitter->item(0)->getAttribute('value'));
        $this->assertSame('https://example.test/second-social', $twitter->item(1)->getAttribute('value'));
        $this->assertSame('', $xpath->query('.//input[@name="patreon_url[]"]', $form)->item(0)->getAttribute('value'));
    }

    public function test_each_social_platform_supports_multiple_links_and_removing_links(): void
    {
        $author = $this->author('Multiple Social Links');
        $this->actingAs($this->manager());
        $payload = ['name' => $author->name];
        foreach (['twitter_url', 'patreon_url', 'fanbox_url', 'pixiv_url'] as $column) {
            $payload[$column] = ['https://example.test/'.$column.'/first', '', 'https://example.test/'.$column.'/second', 'https://example.test/'.$column.'/first'];
        }
        $this->put(route('settings.doujin-authors.update', $author), $payload)
            ->assertRedirect(route('settings.doujin-authors'))
            ->assertSessionHas('status_color', 'green');

        $author->refresh();
        foreach (['twitter_url', 'patreon_url', 'fanbox_url', 'pixiv_url'] as $column) {
            $this->assertSame('https://example.test/'.$column.'/first'."\n".'https://example.test/'.$column.'/second', $author->{$column});
        }
        $response = $this->get(route('settings.doujin-authors'))->assertOk();
        $document = new \DOMDocument();
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $form = $document->getElementById('artist-form-'.$author->id);
        foreach (['twitter_url', 'patreon_url', 'fanbox_url', 'pixiv_url'] as $column) {
            $this->assertSame(2, $xpath->query('.//input[@name="'.$column.'[]"]', $form)->length);
            $payload[$column] = [];
        }
        $payload['twitter_url'] = ['https://example.test/twitter_url/second'];
        $this->put(route('settings.doujin-authors.update', $author), $payload)
            ->assertRedirect(route('settings.doujin-authors'));
        $author->refresh();
        $this->assertSame('https://example.test/twitter_url/second', $author->twitter_url);
        $this->assertNull($author->patreon_url);
        $this->assertNull($author->fanbox_url);
        $this->assertNull($author->pixiv_url);
    }

    public function test_changing_author_replaces_the_current_author_from_settings(): void
    {
        $user = $this->manager();
        $fromAuthor = $this->author('Attach Source');
        $toAuthor = $this->author('Attach Target');
        $media = $this->doujin('Attach Doujin');
        $media->doujinAuthors()->attach($fromAuthor->id);

        $this
            ->actingAs($user)
            ->post(route('settings.doujin-authors.doujins.authors.attach', [
                'author' => $fromAuthor,
                'media' => $media,
            ]), [
                'target_author_id' => $toAuthor->id,
                'new_author' => '',
            ])
            ->assertRedirect(route('settings.doujin-authors'));

        $this->assertDatabaseMissing('doujin_item_author', [
            'media_id' => $media->id,
            'author_id' => $fromAuthor->id,
        ]);
        $this->assertDatabaseHas('doujin_item_author', [
            'media_id' => $media->id,
            'author_id' => $toAuthor->id,
        ]);
        $this->assertSame(1, $media->doujinAuthors()->count());
    }

    public function test_the_only_author_cannot_be_detached_from_a_doujin(): void
    {
        $user = $this->manager();
        $fromAuthor = $this->author('Detach Source');
        $media = $this->doujin('Detach Doujin');
        $media->doujinAuthors()->attach($fromAuthor->id);

        $this
            ->actingAs($user)
            ->delete(route('settings.doujin-authors.doujins.authors.detach', [
                'author' => $fromAuthor,
                'media' => $media,
            ]))
            ->assertRedirect(route('settings.doujin-authors'))
            ->assertSessionHas('status_color', 'red');

        $this->assertDatabaseHas('doujin_item_author', [
            'media_id' => $media->id,
            'author_id' => $fromAuthor->id,
        ]);
    }

    public function test_author_with_connected_doujins_cannot_be_deleted_from_settings(): void
    {
        Storage::fake('public');

        $user = $this->manager();
        $author = $this->author('Delete Source');
        $media = $this->doujin('Delete Doujin');
        $media->doujinAuthors()->attach($author->id);
        Storage::disk('public')->put('doujin/'.$media->id.'/001/001.jpg', 'image');

        $this
            ->actingAs($user)
            ->delete(route('settings.doujin-authors.destroy', $author), [
                'confirm_author_id' => (string) $author->id,
            ])
            ->assertRedirect(route('settings.doujin-authors'));

        $this->assertDatabaseHas('media', ['id' => $media->id]);
        $this->assertDatabaseHas('doujin_authors', ['id' => $author->id]);
        $this->assertDatabaseHas('doujin_item_author', [
            'media_id' => $media->id,
            'author_id' => $author->id,
        ]);
        Storage::disk('public')->assertExists('doujin/'.$media->id.'/001/001.jpg');
    }

    public function test_empty_author_can_be_deleted_from_settings(): void
    {
        $user = $this->manager();
        $author = $this->author('Empty Delete Source');

        $this
            ->actingAs($user)
            ->delete(route('settings.doujin-authors.destroy', $author), [
                'confirm_author_id' => (string) $author->id,
            ])
            ->assertRedirect(route('settings.doujin-authors'));

        $this->assertDatabaseMissing('doujin_authors', ['id' => $author->id]);
    }

    private function manager(): User
    {
        $role = Role::where('role', 'Admin')->first()
            ?? Role::create(['role' => 'Admin']);

        return User::create([
            'username' => 'doujin-author-settings-'.Str::lower(Str::random(10)),
            'email' => Str::lower(Str::random(12)).'@example.test',
            'password' => 'password',
            'status' => 'active',
            'role_id' => $role->role_id,
        ]);
    }

    private function author(string $name): DoujinAuthor
    {
        $name = $name.' '.Str::lower(Str::random(8));

        return DoujinAuthor::create([
            'name' => $name,
            'slug' => Str::slug($name),
        ]);
    }

    private function doujin(string $title): Media
    {
        return Media::create([
            'type' => 'doujin',
            'title_english' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(8)),
        ]);
    }
}
