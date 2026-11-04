@foreach([
    'ANIMES' => 'Animes',
    'MANGAS' => 'Mangas',
    'MANHWAS' => 'Manhwas',
    'LIGHT-NOVELS' => 'Light Novels',
    'HENTAIS' => 'Hentais',
    'DOUJINS' => 'Doujins',
    'VISUAL-NOVEL' => 'Visual Novels',
    'MOVIES' => 'Movies',
] as $navigationCategory => $navigationLabel)
    <a href="{{ route('category', ['category' => $navigationCategory]) }}"
       class="category-nav-link text-white text-sm px-2 py-1 rounded hover:bg-red-600"
       @if(strtoupper((string) request()->route('category')) === $navigationCategory) aria-current="page" @endif>{{ $navigationLabel }}</a>
@endforeach
