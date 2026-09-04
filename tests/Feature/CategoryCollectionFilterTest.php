<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class CategoryCollectionFilterTest extends TestCase
{
    use DatabaseTransactions;

    public function test_category_collection_filter_accepts_multiple_values(): void
    {
        $user = $this->manager();
        $first = Collection::create([
            'name' => 'First Filter '.Str::lower(Str::random(8)),
            'is_system' => false,
        ]);
        $second = Collection::create([
            'name' => 'Second Filter '.Str::lower(Str::random(8)),
            'is_system' => false,
        ]);

        $inBoth = $this->anime('CC Both '.Str::lower(Str::random(6)));
        $firstOnly = $this->anime('CC One '.Str::lower(Str::random(6)));
        $outside = $this->anime('CC Out '.Str::lower(Str::random(6)));

        $this->attachToCollection($first, $inBoth);
        $this->attachToCollection($second, $inBoth);
        $this->attachToCollection($first, $firstOnly);

        $this
            ->actingAs($user)
            ->get(route('category', [
                'category' => 'animes',
                'collection' => $first->id.','.$second->id,
            ]))
            ->assertOk()
            ->assertSee($inBoth->title_english)
            ->assertDontSee($firstOnly->title_english)
            ->assertDontSee($outside->title_english);
    }

    public function test_category_collection_blacklist_accepts_multiple_values(): void
    {
        $user = $this->manager();
        $scope = Collection::create([
            'name' => 'Blacklist Scope '.Str::lower(Str::random(8)),
            'is_system' => false,
        ]);
        $first = Collection::create([
            'name' => 'First Blacklist '.Str::lower(Str::random(8)),
            'is_system' => false,
        ]);
        $second = Collection::create([
            'name' => 'Second Blacklist '.Str::lower(Str::random(8)),
            'is_system' => false,
        ]);

        $visible = $this->anime('CB Show '.Str::lower(Str::random(6)));
        $hiddenByFirst = $this->anime('CB One '.Str::lower(Str::random(6)));
        $hiddenBySecond = $this->anime('CB Two '.Str::lower(Str::random(6)));

        $this->attachToCollection($scope, $visible);
        $this->attachToCollection($scope, $hiddenByFirst);
        $this->attachToCollection($scope, $hiddenBySecond);
        $this->attachToCollection($first, $hiddenByFirst);
        $this->attachToCollection($second, $hiddenBySecond);

        $this
            ->actingAs($user)
            ->get(route('category', [
                'category' => 'animes',
                'collection' => (string) $scope->id,
                'collection_blacklist' => $first->id.','.$second->id,
            ]))
            ->assertOk()
            ->assertSee($visible->title_english)
            ->assertDontSee($hiddenByFirst->title_english)
            ->assertDontSee($hiddenBySecond->title_english);
    }

    private function anime(string $title): Media
    {
        return Media::create([
            'type' => 'anime',
            'title_english' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(8)),
        ]);
    }

    private function attachToCollection(Collection $collection, Media $media): void
    {
        CollectionItem::create([
            'collection_id' => $collection->id,
            'item_type' => 'animes',
            'item_id' => $media->id,
            'title' => $media->title_english,
        ]);
    }

    private function manager(): User
    {
        $role = Role::where('role', 'Admin')->first()
            ?? Role::create(['role' => 'Admin']);

        return User::create([
            'username' => 'category-collection-filter-'.Str::lower(Str::random(10)),
            'email' => Str::lower(Str::random(12)).'@example.test',
            'password' => 'password',
            'status' => 'active',
            'role_id' => $role->role_id,
        ]);
    }
}
