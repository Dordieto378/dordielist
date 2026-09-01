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
            ->assertSee('name="twitter_url"', false)
            ->assertSee('name="patreon_url"', false)
            ->assertSee('name="fanbox_url"', false)
            ->assertSee('name="pixiv_url"', false)
            ->assertSee('placeholder="Patreon URL"', false)
            ->assertSee('Save')
            ->assertDontSee('Hidden Social Artist')
            ->assertDontSee('https://twitter.example/current')
            ->assertDontSee('Edit Artist')
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
            ->assertDontSee('Settings Artist Updated');
    }

    public function test_additional_author_can_be_attached_to_doujin_from_settings(): void
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

        $this->assertDatabaseHas('doujin_item_author', [
            'media_id' => $media->id,
            'author_id' => $fromAuthor->id,
        ]);
        $this->assertDatabaseHas('doujin_item_author', [
            'media_id' => $media->id,
            'author_id' => $toAuthor->id,
        ]);
    }

    public function test_author_can_be_detached_from_multi_author_doujin(): void
    {
        $user = $this->manager();
        $fromAuthor = $this->author('Detach Source');
        $remainingAuthor = $this->author('Detach Target');
        $media = $this->doujin('Detach Doujin');
        $media->doujinAuthors()->attach([$fromAuthor->id, $remainingAuthor->id]);

        $this
            ->actingAs($user)
            ->delete(route('settings.doujin-authors.doujins.authors.detach', [
                'author' => $fromAuthor,
                'media' => $media,
            ]))
            ->assertRedirect(route('settings.doujin-authors'));

        $this->assertDatabaseMissing('doujin_item_author', [
            'media_id' => $media->id,
            'author_id' => $fromAuthor->id,
        ]);
        $this->assertDatabaseHas('doujin_item_author', [
            'media_id' => $media->id,
            'author_id' => $remainingAuthor->id,
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
