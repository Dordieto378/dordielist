<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Favorite;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class CollectionPageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_collection_page_prefers_current_english_media_title_over_cached_item_title(): void
    {
        $user = $this->manager();
        $media = Media::create([
            'type' => 'anime',
            'title_english' => 'Current English Title '.Str::lower(Str::random(8)),
            'title_romaji' => 'Old Romaji Title',
            'slug' => 'current-english-title-'.Str::lower(Str::random(8)),
        ]);
        $collection = Collection::create([
            'name' => 'Title Test Collection '.Str::lower(Str::random(8)),
            'is_system' => false,
        ]);

        CollectionItem::create([
            'collection_id' => $collection->id,
            'item_type' => 'animes',
            'item_id' => $media->id,
            'title' => 'Old Romaji Title',
        ]);

        $this
            ->actingAs($user)
            ->get(route('collection.show', $collection))
            ->assertOk()
            ->assertSee($media->title_english)
            ->assertDontSee('Old Romaji Title');
    }

    public function test_favorites_collection_prefers_current_english_media_title_over_cached_title(): void
    {
        $user = $this->manager();
        $media = Media::create([
            'type' => 'manga',
            'title_english' => 'Favorite English Title '.Str::lower(Str::random(8)),
            'title_romaji' => 'Favorite Romaji Title',
            'slug' => 'favorite-english-title-'.Str::lower(Str::random(8)),
        ]);
        $collection = Collection::create([
            'name' => 'Favorites',
            'is_system' => true,
        ]);

        Favorite::create([
            'favoritable_type' => 'mangas',
            'favoritable_id' => $media->id,
            'title' => 'Favorite Romaji Title',
        ]);

        $this
            ->actingAs($user)
            ->get(route('collection.show', $collection))
            ->assertOk()
            ->assertSee($media->title_english)
            ->assertDontSee('Favorite Romaji Title');
    }

    private function manager(): User
    {
        $role = Role::where('role', 'Admin')->first()
            ?? Role::create(['role' => 'Admin']);

        return User::create([
            'username' => 'collection-page-'.Str::lower(Str::random(10)),
            'email' => Str::lower(Str::random(12)).'@example.test',
            'password' => 'password',
            'status' => 'active',
            'role_id' => $role->role_id,
        ]);
    }
}
