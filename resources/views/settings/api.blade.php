{{-- resources/views/settings/api.blade.php --}}
@extends('layouts.app')

@section('content')
  <div class="bg-gray-100 mb-[50px] pt-[100px]">
    <div class="max-w-[1320px] mx-auto px-4 sm:px-6 lg:px-8 flex space-x-6 text-sm">

      @include('settings.partials.sidebar')

      <main class="w-3/4">
        <div class="bg-white shadow-md rounded-lg overflow-hidden">
          <div class="p-8">
            <div class="flex items-center justify-between">
              <h1 class="text-2xl font-bold text-red-600">API</h1>
            </div>

            @if(session('status'))
              <div class="app-alert {{ session('status_color') === 'red' ? 'app-alert-error' : 'app-alert-success' }} mt-4">
                {{ session('status') }}
              </div>
            @endif

            @if(session('error'))
              <div class="app-alert app-alert-error mt-4">
                {{ session('error') }}
              </div>
            @endif

            @if(session('success'))
              <div class="app-alert app-alert-success mt-4">
                {{ session('success') }}
              </div>
            @endif

            <form method="POST" action="{{ route('settings.api.update') }}" class="mt-4">
              @csrf
              @method('PUT')

              <div class="mt-4">
                <label for="anilist_access_token" class="block font-medium text-[17px] text-red-600">
                  AniList Access Token
                </label>
                <div class="relative mt-1">
                  <input
                    type="password"
                    id="anilist_access_token"
                    name="anilist_access_token"
                    value="{{ old('anilist_access_token', $user->anilist_access_token) }}"
                    autocomplete="off"
                    class="w-full rounded-md border border-gray-200 py-2 pl-3 pr-3 text-base
                           bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600
                           text-gray-800 font-medium"
                  />
                </div>
              </div>

              <div class="mt-6">
                <label for="tmdb_api_token" class="block font-medium text-[17px] text-red-600">
                  TMDb API Read Access Token
                </label>
                <div class="relative mt-1">
                  <input
                    type="password"
                    id="tmdb_api_token"
                    name="tmdb_api_token"
                    value="{{ old('tmdb_api_token', $user->tmdb_api_token) }}"
                    autocomplete="off"
                    @unless($user->tmdb_session_id)
                    aria-describedby="tmdb_api_token_help"
                    @endunless
                    class="w-full rounded-md border border-gray-200 py-2 pl-3 pr-3 text-base
                           bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600
                           text-gray-800 font-medium"
                  />
                </div>
                <div class="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                  @unless($user->tmdb_session_id)
                    <p id="tmdb_api_token_help" class="text-xs font-normal normal-case text-gray-500">
                      @if($user->tmdb_api_token ?: config('services.tmdb.token'))
                        Connect your account to update movie lists.
                      @else
                        Save your TMDb API Read Access Token before connecting your account.
                      @endif
                    </p>
                  @endunless
                  @if($user->tmdb_session_id)
                    <button type="submit" form="tmdb-disconnect-form" aria-label="Disconnect TMDb Account"
                            class="ml-auto rounded text-xs font-medium normal-case text-gray-500 transition hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-600 focus:ring-offset-2">
                      Disconnect
                    </button>
                  @elseif($user->tmdb_api_token ?: config('services.tmdb.token'))
                    <button type="submit" form="tmdb-connect-form"
                            class="inline-flex items-center gap-1.5 rounded text-xs font-medium normal-case text-red-600 transition hover:text-red-700 focus:outline-none focus:ring-2 focus:ring-red-600 focus:ring-offset-2">
                      <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M10 13a5 5 0 0 0 7.07 0l3-3A5 5 0 0 0 13 2.93l-1.72 1.71" />
                        <path d="M14 11a5 5 0 0 0-7.07 0l-3 3A5 5 0 0 0 11 21.07l1.71-1.71" />
                      </svg>
                      Connect TMDb Account
                    </button>
                  @endif
                </div>
              </div>

              <div class="mt-6">
                <label for="vndb_api_token" class="block font-medium text-[17px] text-red-600">
                  VNDB API Token
                </label>
                <div class="relative mt-1">
                  <input
                    type="password"
                    id="vndb_api_token"
                    name="vndb_api_token"
                    value="{{ old('vndb_api_token', $user->vndb_api_token) }}"
                    autocomplete="off"
                    class="w-full rounded-md border border-gray-200 py-2 pl-3 pr-3 text-base
                           bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600
                           text-gray-800 font-medium"
                  />
                </div>
              </div>

              <div class="mt-6">
                <label for="vndb_username" class="block font-medium text-[17px] text-red-600">
                  VNDB Username
                </label>
                <div class="relative mt-1">
                  <input
                    type="text"
                    id="vndb_username"
                    name="vndb_username"
                    value="{{ old('vndb_username', $user->vndb_username) }}"
                    class="w-full rounded-md border border-gray-200 py-2 pl-3 pr-3 text-base
                           bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600
                           text-gray-800 font-medium"
                  />
                </div>
              </div>

              <div class="mt-6">
                <label for="vndb_password" class="block font-medium text-[17px] text-red-600">
                  VNDB Password
                </label>
                <div class="relative mt-1">
                  <input
                    type="password"
                    id="vndb_password"
                    name="vndb_password"
                    value="{{ old('vndb_password', $user->vndb_password) }}"
                    autocomplete="off"
                    class="w-full rounded-md border border-gray-200 py-2 pl-3 pr-3 text-base
                           bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600
                           text-gray-800 font-medium"
                  />
                </div>
              </div>

              <div class="mt-8">
                <button
                  type="submit"
                  class="h-12 w-40 mx-auto rounded-md bg-[#08875b] text-white text-lg
                         hover:bg-emerald-700 focus:outline-none"
                >
                  Save Settings
                </button>
              </div>
            </form>

            @if($user->tmdb_session_id)
              <form id="tmdb-disconnect-form" method="POST" action="{{ route('settings.api.tmdb.disconnect') }}" class="hidden">
                @csrf
                @method('DELETE')
              </form>
            @elseif($user->tmdb_api_token ?: config('services.tmdb.token'))
              <form id="tmdb-connect-form" method="POST" action="{{ route('settings.api.tmdb.connect') }}" class="hidden">
                @csrf
              </form>
            @endif

          </div>
        </div>
      </main>
    </div>
  </div>
@endsection
