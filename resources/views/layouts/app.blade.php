<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <script src="//unpkg.com/alpinejs" defer></script>
        <link rel="stylesheet" href="https://cdn.plyr.io/3.7.8/plyr.css" />
    <title>Dordielist</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100 font-sans text-white text-base font-semibold capitalize">
    <!-- Navbar -->
    <nav class="bg-flatRed tracking-wide fixed top-0 left-0 w-full z-50">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            @guest
                <div class="flex-1"></div>
                <div class="flex-1 flex justify-center">
                    <a href="{{ route('home') }}" class="text-2xl font-bold text-white">DORDIELIST</a>
                </div>
                <div class="flex-1"></div>
            @else
                <div class="flex items-center space-x-1">
                    <!-- Title -->
                    <a href="{{ route('home') }}" class="text-2xl font-bold ml-[6.2rem] pr-2">DORDIELIST</a>
                    <!-- Menu Items -->
                    <div class="hidden md:flex items-center">
                        <a href="{{ route('category', ['category' => 'ANIMES']) }}" class="text-white text-sm px-2 py-1 rounded hover:bg-red-600">Animes</a>
                        <a href="{{ route('category', ['category' => 'MANGAS']) }}" class="text-white text-sm px-2 py-1 rounded hover:bg-red-600">Mangas</a>
                        <a href="{{ route('category', ['category' => 'MANWHAS']) }}" class="text-white text-sm px-2 py-1 rounded hover:bg-red-600">Manwhas</a>
                        <a href="{{ route('category', ['category' => 'HENTAIS']) }}" class="text-white text-sm px-2 py-1 rounded hover:bg-red-600">Hentais</a>
                        <div class="h-4 w-0.5 bg-gray-300 bg-opacity-25 rounded mx-1"></div>
                        <a href="{{ route('category', ['category' => 'DOUJINS']) }}" class="text-white text-sm px-2 py-1 rounded hover:bg-red-600">Doujins</a>
                        <div class="h-4 w-0.5 bg-gray-300 bg-opacity-25 rounded mx-1"></div>
                        <a href="{{ route('category', ['category' => 'VISUAL-NOVEL']) }}" class="text-white text-sm px-2 py-1 rounded hover:bg-red-600">Visual Novels</a>
                    </div>
                </div>
                <div class="flex items-center space-x-4 mr-[7.3rem]">
                    <button id="searchButton" type="button" class="relative group w-[80px] md:w-[170px] h-[40px] rounded-[0.8rem] pl-10 pr-4 flex items-center justify-center transition-all group dark:bg-gray-800 dark:bg-opacity-20 text-gray-300 hover:bg-red-600 text-opacity-50 group-hover:opacity-100 hover:text-white">
                        <span class="text-sm">Quick search...</span>
                        <svg xmlns="http://www.w3.org/2000/svg"
                            class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5"
                            fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M21 21l-4.35-4.35m1.65-6.15a7.5 7.5 0 11-15 0 7.5 7.5 0 0115 0z" />
                        </svg>
                    </button>

                    <div class="relative group ml-4 py-1 px-2 rounded hover:bg-red-600">
                        <button
                        id="accountToggle"
                        class="menu-link-text flex items-center js-my-account-links"
                        data-target="my-account-drop-links"
                        aria-haspopup="true"
                        aria-expanded="false"
                        >
                            <span class="sr-only">My Account</span>
                            <span class="font-semibold pr-2">
                                <svg
                                class="size-4 fill-white h-4 w-4"
                                xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 448 512"
                                >
                                <path
                                    d="M224 256A128 128 0 1 0 224 0a128 128 0 1 0 0 256zm-45.7 48C79.8
                                    304 0 383.8 0 482.3C0 498.7 13.3 512 29.7 512l388.6 0c16.4 0
                                    29.7-13.3 29.7-29.7C448 383.8 368.2 304 269.7 304l-91.4 0z"
                                />
                                </svg>
                            </span>
                            <svg xmlns="http://www.w3.org/2000/svg"
                                width="22" height="22"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round">
                            <polyline points="6 9 12 15 18 9"/>
                            </svg>
                        </button>
                        <ul
                        id="my-account-drop-links"
                        class="absolute right-0 mt-2 w-[100px] bg-white text-gray-800 rounded-[0.2rem] shadow-lg
                                opacity-0 pointer-events-none transition-opacity font-medium text-sm text-left list-none p-0 m-0"
                        >
                        <li>
                            <button
                            class="w-full text-left pl-3 pr-3 py-3.5 hover:bg-gray-100 rounded-[0.2rem]"
                            >
                            <a href="{{ route('collection.index') }}">Collections</a>
                            </button>
                        </li>
                        @php
                        // Grab the system “Favorites” collection
                        $favorites = \App\Models\Collection::where('is_system', true)->first();
                        @endphp

                        <li>
                        <a
                            href="{{ route('collection.show', $favorites) }}"
                            class="block w-full text-left pl-3 pr-3 py-3.5 hover:bg-gray-100 rounded-[0.2rem] text-gray-800"
                        >
                            Favorites
                        </a>
                        </li>
                        <li>
                        <a href="{{ route('settings.profile.edit') }}"
                            class="block w-full text-left pl-3 pr-3 py-3.5 hover:bg-gray-100 rounded-[0.2rem] text-gray-800">
                            Settings
                        </a>
                        </li>
                        <li>
                            <form
                            method="POST"
                            action="{{ route('logout') }}"
                            class="block w-full m-0 p-0"
                            >
                            @csrf
                            <button
                                type="submit"
                                class="w-full text-left pl-3 pr-3 py-3.5 hover:bg-gray-100 rounded-[0.2rem]"
                            >
                                Logout
                            </button>
                            </form>
                        </li>
                        </ul>
                    </div>
                </div>
            @endguest
        </div>
    </nav>

    @yield('content')

    <!-- Footer Section -->
    <footer class="bg-gray-100 text-gray-600 text-sm font-medium">
        <div class="flex justify-center">
            <div class="border-t border-gray-300 w-[1350px]"></div> <!-- Centered Thin Line -->
        </div>
        <div class="container mx-auto flex justify-between items-end py-6 px-24">
            <!-- Left Side: Copyright -->
            <p class=" text-gray-500">&copy; DORDIELIST, LLC 2025 All rights reserved.</p>

            <!-- Right Side: Navigation Links -->
            <div class="flex space-x-4">
                <a href="#" class="text-gray-500 hover:text-gray-700">Contact</a>
                <a href="#" class="text-gray-500 hover:text-gray-700">Support</a>
                <a href="#" class="text-gray-500 hover:text-gray-700">Jobs</a>
                <a href="#" class="text-gray-500 hover:text-gray-700">Terms</a>
                <a href="#" class="text-gray-500 hover:text-gray-700">Privacy</a>
                <a href="#" class="text-gray-500 hover:text-gray-700">Merch</a>
            </div>
        </div>
    </footer>

    <div id="popupOverlay" class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-[1.8px] flex justify-center items-start pt-[2.9rem] font-boldness hidden z-50">
        <!-- Search Container -->
        <div class="relative w-[70%] md:w-[580px]">
            <!-- Search Input -->
            <input type="text" id="searchInput"
                placeholder="Search..."
                class="w-full py-4 pl-12 pr-4 text-gray-700 border border-gray-300 rounded-lg outline-none bg-white shadow-md">

            <!-- Search Icon -->
            <svg xmlns="http://www.w3.org/2000/svg"
                class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-black"
                fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                    d="M21 21l-4.35-4.35m1.65-6.15a7.5 7.5 0 11-15 0 7.5 7.5 0 0115 0z" />
            </svg>

            <!-- Search Results Dropdown -->
            <div id="searchResults" class="absolute top-full left-0 w-full bg-white shadow-lg rounded-lg mt-4 pb-4 hidden">
                <!-- Results will be injected here -->
            </div>
        </div>
    </div>

    <script src="https://cdn.plyr.io/3.7.8/plyr.polyfilled.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const isGuest       = @json(!Auth::check());
            const searchButton  = document.getElementById("searchButton");
            const popupOverlay  = document.getElementById("popupOverlay");
            const body          = document.body;
            const searchInput   = document.getElementById("searchInput");
            const searchResults = document.getElementById("searchResults");
            const toggleBtn = document.getElementById('accountToggle');
            const menu      = document.getElementById('my-account-drop-links');

            let aborter = null;
            let debTimer = null;

            // show popup
            searchButton.addEventListener("click", () => {
                popupOverlay.classList.remove("hidden");
                body.classList.add("overflow-hidden");
                searchInput.focus();
            });

            // hide popup (click backdrop / ESC)
            popupOverlay.addEventListener("click", (e) => {
                if (e.target === popupOverlay) {
                    popupOverlay.classList.add("hidden");
                    body.classList.remove("overflow-hidden");
                }
            });
            document.addEventListener("keydown", (e) => {
                if (e.key === "Escape") {
                    popupOverlay.classList.add("hidden");
                    body.classList.remove("overflow-hidden");
                }
            });

            function highlightMatch(text, query) {
                if (!query) return text;
                const rx = new RegExp(`(${query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, "gi");
                return text.replace(rx, `<span class="text-red-600 underline">$1</span>`);
            }

            function renderResults(items, query) {
                searchResults.innerHTML = "";
                if (!items.length) {
                    searchResults.innerHTML = `<div class="p-4 text-gray-500">No results found</div>`;
                    searchResults.classList.remove("hidden");
                    return;
                }

                items.slice(0, 10).forEach(item => {
                    const title = item.title.english || item.title.romaji || "No Title";

                    const row = document.createElement("div");
                    row.className = "flex items-center p-3 cursor-pointer rounded-lg group";

                    row.innerHTML = `
        <span class="relative inline-block w-12 h-12 flex-shrink-0 mr-3">
          <img src="${item.coverImage?.extraLarge ?? '/images/no-image.jpg'}"
               alt="Cover" class="w-full h-auto max-h-16 rounded object-contain">
        </span>
        <div>
          <p class="font-semibold text-black group-hover:text-red-600">
            ${highlightMatch(title, query)}
          </p>
          <p class="text-gray-600 text-sm">${(item.type || '').replace('-', ' ')}</p>
        </div>
      `;

                    row.addEventListener("click", () => {
                        const t = (item.type || '').toLowerCase();
                        if (t === 'visual-novel') {
                            window.location.href = `/vn/${item.id}`;
                        } else if (t === 'doujin') {
                            window.location.href = `/doujin/${item.id}`;
                        } else {
                            // anime/manga/hentai/manwha/others
                            window.location.href = `/media/${item.id}`;
                        }
                    });

                    searchResults.appendChild(row);
                });

                searchResults.classList.remove("hidden");
            }

            async function doSearch(q) {
                if (aborter) aborter.abort();
                aborter = new AbortController();

                searchResults.classList.remove("hidden");
                searchResults.innerHTML = `<div class="p-4 text-gray-500">Loading...</div>`;

                try {
                    const res = await fetch(`/search?q=${encodeURIComponent(q)}&limit=20`, {
                        signal: aborter.signal
                    });
                    const data = await res.json();
                    renderResults(Array.isArray(data) ? data : [], q);
                } catch (e) {
                    if (e.name === 'AbortError') return;
                    console.error(e);
                    searchResults.innerHTML = `<div class="p-4 text-gray-500">Error loading results</div>`;
                    searchResults.classList.remove("hidden");
                }
            }

            searchInput.addEventListener("input", function () {
                const q = this.value.trim();
                if (!q) {
                    searchResults.classList.add("hidden");
                    return;
                }
                clearTimeout(debTimer);
                debTimer = setTimeout(() => doSearch(q), 250);
            });

            // click outside the dropdown hides it
            document.addEventListener("click", (e) => {
                if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                    searchResults.classList.add("hidden");
                }
            });

            // If user is a guest, those elements won't exist—just bail.
            if (!toggleBtn || !menu) return;

            function openMenu() {
                menu.classList.remove('opacity-0', 'pointer-events-none');
            }
            function closeMenu() {
                menu.classList.add('opacity-0', 'pointer-events-none');
            }
            function isOpen() {
                return !menu.classList.contains('opacity-0');
            }

            toggleBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                isOpen() ? closeMenu() : openMenu();
            });

            // Clicks outside close it
            document.addEventListener('click', (e) => {
                if (!menu.contains(e.target) && !toggleBtn.contains(e.target)) {
                    closeMenu();
                }
            });

            // ESC closes it
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') closeMenu();
            });
        });
    </script>

</body>
</html>
