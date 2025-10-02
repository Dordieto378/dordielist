{{-- resources/views/settings/account.blade.php --}}
@extends('layouts.app')

@section('content')
  <div class="bg-gray-100 mb-[50px] pt-[100px]">
    <div class="max-w-[1320px] mx-auto px-4 sm:px-6 lg:px-8 flex space-x-6 text-sm">

      {{-- LEFT: Shared sidebar (we’ll extract this in step 5) --}}
      @include('settings.partials.sidebar')

      {{-- RIGHT: Account (“Edit Profile”) form --}}
      <main class="w-3/4">
        <div class="bg-white shadow-md rounded-lg overflow-hidden">
          <div class="p-8">
            <div class="flex items-center justify-between">
              <h1 class="text-2xl font-bold text-red-600">Profile Settings</h1>
            </div>

            {{-- ► FLASH MESSAGE (either success in green or error in red) --}}
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
              <div class="mt-4 p-4 {{ $bg }} border rounded">
                {{ session('status') }}
              </div>
            @endif

            <form method="POST" action="{{ route('settings.profile.update') }}" class="mt-4">
              @csrf
              @method('PUT')

              <div class="mt-4">
                <label for="username" class="block font-medium text-[17px] text-red-600">
                  Username
                </label>
                <div class="relative mt-1">
                  <input
                    type="text"
                    id="username"
                    name="username"
                    value="{{ old('username', $user->username) }}"
                    class="w-full rounded-md border border-gray-200 py-2 pl-3 pr-3 text-base
                           bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600
                           text-gray-800 font-medium"
                  />
                </div>
              </div>

              <div class="mt-6">
                <label for="email" class="block font-medium text-[17px] text-red-600">
                  Email
                </label>
                <div class="relative mt-1">
                  <input
                    type="email"
                    id="email"
                    name="email"
                    value="{{ old('email', $user->email) }}"
                    class="w-full rounded-md border border-gray-200 py-2 pl-3 pr-10 text-base
                           bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600
                           text-gray-800 font-medium"
                  />
                </div>
              </div>

              <div class="mt-6">
                <label for="password" class="block font-medium text-[17px] text-red-600">
                  New Password <span class="text-gray-600">(leave blank to keep current)</span>
                </label>
                <div class="relative mt-1">
                  <input
                    type="password"
                    id="password"
                    name="password"
                    class="w-full rounded-md border border-gray-200 py-2 pl-3 pr-10 text-base
                           bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600
                           text-gray-800 font-medium"
                  />
                </div>
              </div>

              <div class="mt-6">
                <label for="password_confirmation" class="block font-medium text-[17px] text-red-600">
                  Confirm New Password
                </label>
                <div class="relative mt-1">
                  <input
                    type="password"
                    id="password_confirmation"
                    name="password_confirmation"
                    class="w-full rounded-md border border-gray-200 py-2 pl-3 pr-10 text-base
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
                  Save Profile
                </button>
              </div>
            </form>
          </div>
        </div>
      </main>
    </div>
  </div>
@endsection
