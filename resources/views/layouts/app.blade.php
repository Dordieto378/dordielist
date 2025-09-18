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
                @guest
                <a href="{{ route('login') }}" class="text-white text-sm py-1 px-2 rounded hover:bg-red-600">Login</a>
                <a href="{{ route('register') }}" class="bg-red-600 text-sm text-white px-2 py-1 rounded">Register</a>
                @endguest

                @auth
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
                    <a href="{{ route('settings.security') }}"
                        class="block w-full text-left pl-3 pr-3 py-3.5 hover:bg-gray-100 rounded-[0.2rem] text-gray-800">
                        2FA
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
                @endauth
            </div>
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
            <div id="searchResults" class="absolute top-full left-0 w-full bg-white shadow-lg rounded-lg mt-4 hidden">
                <!-- Results will be injected here -->
            </div>
        </div>
    </div>

    <script src="https://cdn.plyr.io/3.7.8/plyr.polyfilled.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const toggleBtn = document.getElementById('accountToggle');
            const wrapper   = toggleBtn.closest('div.relative');

            toggleBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            wrapper.classList.toggle('open-dropdown');
            });

            // clicking anywhere else closes it
            document.addEventListener('click', () => {
            wrapper.classList.remove('open-dropdown');
            });
        });
       document.addEventListener("DOMContentLoaded", function () {
        // 0) “Are we a guest?” → inline for blur logic
        const isGuest = @json(!Auth::check());

        const searchButton  = document.getElementById("searchButton");
        const popupOverlay  = document.getElementById("popupOverlay");
        const body          = document.body;
        const searchInput   = document.getElementById("searchInput");
        const searchResults = document.getElementById("searchResults");

        let allMedia  = null; // Will hold the combined AniList+VNDB list
        let isLoading = false; // Are we currently fetching?

        // 1) Show popup & fetch data if needed
        searchButton.addEventListener("click", async () => {
            popupOverlay.classList.remove("hidden");
            body.classList.add("overflow-hidden");

            if (allMedia === null && !isLoading) {
                isLoading = true;
                try {
                    // Fetch AniList + VNDB in parallel
                    const [aniRes, vnRes, doujinRes] = await Promise.all([
                        fetch("/api/media"),
                        fetch("/api/vndb-media"),
                        fetch("/api/doujins")
                    ]);

                    const aniList = await aniRes.json();
                    const vnList  = await vnRes.json();
                    const doujinList = await doujinRes.json();

                    // Normalize VNDB entries into “AniList‐like” objects, adding isAdult = !hasNoSexualContent
                    const vnItems = vnList.map(vn => ({
                        id:         vn.id,         // prefix so IDs don’t collide
                        coverImage: { extraLarge: vn.image.url },
                        title:      { english: vn.title, romaji: vn.title },
                        type:       "Visual novel",
                        genres:     [],                   // if you want, map vn.tags → genres
                        countryOfOrigin: "",             // optional
                        // **Here’s the key part**:
                        // If the VN does NOT have “No sexual content,” then isAdult = true (we must blur).
                        isAdult:    ! (vn.hasNoSexualContent === true)
                    }));

                    allMedia = [...aniList, ...vnItems, ...doujinList];
                } catch (err) {
                    console.error("Error fetching media lists:", err);
                    allMedia = [];
                }
                isLoading = false;

                // If the user already typed a query, run search again
                performSearch(searchInput.value.trim().toLowerCase());
            }
        });

        // 2) Hide popup if clicked outside or on ESC
        popupOverlay.addEventListener("click", (event) => {
            if (event.target === popupOverlay) {
                popupOverlay.classList.add("hidden");
                body.classList.remove("overflow-hidden");
            }
        });
        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape") {
                popupOverlay.classList.add("hidden");
                body.classList.remove("overflow-hidden");
            }
        });

        // 3) Highlight helper
        function highlightMatch(text, query) {
            if (!query) return text;
            const regex = new RegExp(`(${query})`, "gi");
            return text.replace(regex, `<span class="text-red-600 underline">$1</span>`);
        }

        // 4) Media‐type helper (unchanged)
        function getMediaType(item) {
            const type    = (item.type || "").toUpperCase();
            const genres  = (item.genres || []).map(g => g.toLowerCase());
            const country = (item.countryOfOrigin || "").toUpperCase();

            if (type === "ANIME") {
                return genres.includes("hentai") ? "Hentai" : "Anime";
            } else if (type === "MANGA") {
                if (genres.includes("hentai")) return "Doujin";
                if (country === "KR") return "Manwha";
                return "Manga";
            }
            return type.charAt(0) + type.slice(1).toLowerCase() || "Unknown";
        }

        // 5) Main search function (blurs based on item.isAdult && isGuest)
        function performSearch(query) {
            searchResults.innerHTML = "";

            // Hide results if empty query
            if (!query) {
                searchResults.classList.add("hidden");
                return;
            }

            // Show “Loading…” if still fetching
            if (isLoading || allMedia === null) {
                searchResults.innerHTML = `<div class="p-4 text-gray-500">Loading...</div>`;
                searchResults.classList.remove("hidden");
                return;
            }

            // Filter allMedia by title match
            const filteredMedia = allMedia.filter((item) => {
                const titleLower = (item.title.english || item.title.romaji || "").toLowerCase();
                return titleLower.includes(query);
            });

            // If no matches
            if (filteredMedia.length === 0) {
                searchResults.innerHTML = `<div class="p-4 text-gray-500">No results found</div>`;
                searchResults.classList.remove("hidden");
                return;
            }

            // Otherwise, show up to 10 results
            filteredMedia.slice(0, 6).forEach((item) => {
                const title            = item.title.english || item.title.romaji || "No Title";
                const highlightedTitle = highlightMatch(title, query);

                // Determine NSFW for both AniList & VNDB items:
                //  - AniList items have item.isAdult from the API.
                //  - VNDB items got isAdult = !hasNoSexualContent in our mapping above.
                const shouldBlur = (item.isAdult === true) && isGuest;

                // Build a flex row. The thumbnail wrapper is a relative 48×48, so
                // the badge can absolutely position itself over that <img>.
                const resultItem = document.createElement("div");
                resultItem.className = "flex items-center p-3 cursor-pointer rounded-lg group";

                resultItem.innerHTML = `
                <span class="relative inline-block w-12 h-12 flex-shrink-0 mr-3">
                    ${shouldBlur
                    ? `<img
                        src="/images/18-plus.png"
                        alt="18+"
                        class="absolute top-0 right-0 w-4 h-4 z-10"
                        >`
                    : ``
                    }
                    <img
                    src="${item.coverImage.extraLarge}"
                    alt="Cover"
                    class="w-full h-full rounded object-cover ${shouldBlur ? 'filter blur-2xl' : ''}"
                    >
                </span>
                <div>
                    <p class="font-semibold text-black group-hover:text-red-600">
                    ${highlightedTitle}
                    </p>
                    <p class="text-gray-600 text-sm">${getMediaType(item)}</p>
                </div>
                `;

                // Navigate on click
                resultItem.addEventListener("click", () => {
                const type = (item.type || "").toLowerCase();
                // 1) Doujin → /doujin/{id}
                if (type === "doujin") {
                    // if item.id is numeric, no need to strip a prefix
                    const numericId = item.id.replace(/^doujin/, "");
                    window.location.href = "/doujin/" + numericId;

                // 2) Anime / Manga / Hentai / Manwha → /media/{id}
                } else if (["anime","manga","hentai","manwha"].includes(type)) {
                    window.location.href = `/media/${item.id}`;

                // 3) Visual Novel → /vn/{id}
                } else if (type === "visual novel") {
                    window.location.href = `/vn/${item.id}`;

                // 4) Fallback
                } else {
                    window.location.href = `/media/${item.id}`;
                }
                });


                searchResults.appendChild(resultItem);
            });

            searchResults.classList.remove("hidden");
        }

        // 6) Attach key listener to input
        searchInput.addEventListener("input", function () {
            performSearch(this.value.trim().toLowerCase());
        });

        // 7) Clicking outside hides results
        document.addEventListener("click", (event) => {
            if (!searchInput.contains(event.target) && !searchResults.contains(event.target)) {
                searchResults.classList.add("hidden");
            }
        });
    });
    </script>
</body>
</html>
