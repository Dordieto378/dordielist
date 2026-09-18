<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use App\Models\VnLanguage;
use App\Models\VnPublisher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class VisualNovelCategoryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_visual_novel_language_filter_shows_country_flags_and_names_instead_of_codes(): void
    {
        $user = $this->manager();
        $media = Media::create([
            'type' => 'vn',
            'title_english' => 'Language Label Test '.Str::lower(Str::random(8)),
            'slug' => 'language-label-test-'.Str::lower(Str::random(8)),
        ]);

        $english = VnLanguage::firstOrCreate(['name' => 'en']);
        $japanese = VnLanguage::firstOrCreate(['name' => 'ja']);

        $media->vnLanguages()->attach([$english->id, $japanese->id]);

        $this
            ->actingAs($user)
            ->get(route('category', ['category' => 'visual-novel', 'language' => 'en,ja']))
            ->assertOk()
            ->assertSee('class="fi fi-gb shrink-0"', false)
            ->assertSee('class="fi fi-jp shrink-0"', false)
            ->assertSee('title="English"', false)
            ->assertSee('title="Japanese"', false)
            ->assertSee('value="en"', false)
            ->assertSee('value="ja"', false)
            ->assertSee('<span>English</span>', false)
            ->assertSee('<span>Japanese</span>', false)
            ->assertDontSee('>en</span>', false)
            ->assertDontSee('>ja</span>', false);
    }

    public function test_visual_novel_detail_shows_publishers_with_language_badges(): void
    {
        $user = $this->manager();
        $media = Media::create([
            'type' => 'vn',
            'title_english' => 'Publisher Label Test '.Str::lower(Str::random(8)),
            'slug' => 'publisher-label-test-'.Str::lower(Str::random(8)),
        ]);
        $publisherName = 'Publisher Test '.Str::lower(Str::random(8));

        $publisher = VnPublisher::create([
            'name' => $publisherName,
            'language' => 'en',
            'source_id' => 123,
        ]);

        $media->vnPublishers()->attach($publisher->id);

        $this
            ->actingAs($user)
            ->get(route('vn.show', ['id' => $media->id]))
            ->assertOk()
            ->assertSee('Publishers')
            ->assertSee($publisherName)
            ->assertSee('title="English"', false)
            ->assertSee('aria-label="English"', false)
            ->assertSee('class="fi fi-gb shrink-0"', false);
    }

    private function manager(): User
    {
        $role = Role::where('role', 'Admin')->first()
            ?? Role::create(['role' => 'Admin']);

        return User::create([
            'username' => 'visual-novel-category-'.Str::lower(Str::random(10)),
            'email' => Str::lower(Str::random(12)).'@example.test',
            'password' => 'password',
            'status' => 'active',
            'role_id' => $role->role_id,
        ]);
    }
}
