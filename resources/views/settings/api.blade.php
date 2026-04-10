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
              @php
                $bg = session('status_color') === 'red'
                        ? 'bg-red-100 border-red-200 text-red-800'
                        : (
                            session('status_color') === 'green'
                            ? 'bg-green-100 border-green-200 text-green-800'
                            : 'bg-gray-100 border-gray-200 text-gray-800'
                        );
              @endphp
              <div class="app-alert mt-4 {{ session('status_color') === 'red' ? 'app-alert-error' : (session('status_color') === 'green' ? 'app-alert-success' : 'app-alert-neutral') }}">
                {{ session('status') }}
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
                    type="text"
                    id="anilist_access_token"
                    name="anilist_access_token"
                    value="{{ old('anilist_access_token', $user->anilist_access_token) }}"
                    class="w-full rounded-md border border-gray-200 py-2 pl-3 pr-3 text-base
                           bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600
                           text-gray-800 font-medium"
                  />
                </div>
              </div>

              <div class="mt-6">
                <label for="vndb_api_token" class="block font-medium text-[17px] text-red-600">
                  VNDB API Token
                </label>
                <div class="relative mt-1">
                  <input
                    type="text"
                    id="vndb_api_token"
                    name="vndb_api_token"
                    value="{{ old('vndb_api_token', $user->vndb_api_token) }}"
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
                    type="text"
                    id="vndb_password"
                    name="vndb_password"
                    value="{{ old('vndb_password', $user->vndb_password) }}"
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
          </div>
        </div>
      </main>
    </div>
  </div>
@endsection
