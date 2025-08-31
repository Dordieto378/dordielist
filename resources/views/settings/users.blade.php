{{-- resources/views/settings/users.blade.php --}}
@extends('layouts.app')

@section('content')
@if(optional(auth()->user()->role)->role === 'Admin')
  <div class="bg-gray-100 mb-[50px] pt-[100px]">
    <div class="max-w-[1320px] mx-auto px-4 sm:px-6 lg:px-8 flex space-x-6 text-sm">
      
      {{-- LEFT: shared sidebar --}}
      @include('settings.partials.sidebar')

      {{-- RIGHT: Users content --}}
      <main class="w-3/4">
        <div class="bg-white shadow-md rounded-lg overflow-hidden p-8">
          <h1 class="text-2xl font-bold text-red-600 mb-4">Users</h1>

          {{-- Table wrapper --}}
          <div class="overflow-x-auto ">
            <table class="min-w-full table-auto bg-white">
              <thead>
                <tr class="border-b border-gray-200">
                  <th class="px-6 py-3 font-medium text-left text-gray-800 uppercase text-center tracking-wider">ID</th>
                  <th class="px-6 py-3 font-medium text-left text-gray-800 uppercase text-center tracking-wider">Username</th>
                  <th class="px-6 py-3 font-medium text-left text-gray-800 uppercase text-center tracking-wider">Email</th>
                  <th class="px-6 py-3 font-medium text-left text-gray-800 uppercase text-center tracking-wider">Role</th>
                  <th class="px-6 py-3 font-medium text-left text-gray-800 uppercase text-center tracking-wider">Status</th>
                  <th class="px-6 py-3 font-medium text-left text-gray-800 uppercase text-center tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-200 font-medium text-center">
                @foreach($users as $user)
                  <tr>
                    {{-- ID --}}
                    <td class="px-6 py-4 whitespace-nowrap text-gray-800">
                      {{ $user->user_id }}
                    </td>

                    {{-- Username --}}
                    <td class="px-6 py-4 whitespace-nowrap text-gray-800">
                      {{ $user->username }}
                    </td>

                    {{-- Email --}}
                    <td class="px-6 py-4 whitespace-nowrap text-gray-800">
                      {{ $user->email }}
                    </td>

                    {{-- Role (falls back to “—” if not set) --}}
                    <td class="px-6 py-4 whitespace-nowrap text-gray-800">
                      {{ optional($user->role)->role ?? '—' }}
                    </td>

                    {{-- Status --}}
                    <td class="px-6 py-4 whitespace-nowrap">
                      @if($user->status === 'active')
                        <span class="inline-flex px-2 text-sm font-semibold leading-5 rounded-full bg-green-100 text-green-800">
                          Active
                        </span>
                      @elseif($user->status === 'not_active')
                        <span class="inline-flex px-2 text-sm font-semibold leading-5 rounded-full bg-yellow-100 text-yellow-800">
                          Not Active
                        </span>
                      @elseif($user->status === 'banned')
                        <span class="inline-flex px-2 text-sm font-semibold leading-5 rounded-full bg-red-100 text-red-800">
                          Banned
                        </span>
                      @else
                        <span class="inline-flex px-2 text-sm font-semibold leading-5 rounded-full bg-gray-100 text-gray-800">
                          {{ ucfirst($user->status) }}
                        </span>
                      @endif
                    </td>
                    {{-- Actions (e.g. Edit—adjust routes as needed) --}}
                    <td class="px-6 py-4 whitespace-nowrap text-blue-600 hover:underline">
                      <a href="" class="text-blue-600 hover:text-blue-800">
                        Edit
                      </a>
                    </td>
                  </tr>
                @endforeach

                @if($users->isEmpty())
                  <tr>
                    <td colspan="6" class="px-6 py-4 whitespace-nowrap text-center text-gray-800">
                      No users found.
                    </td>
                  </tr>
                @endif
              </tbody>
            </table>
          </div>
        </div>
      </main>
    </div>
  </div>
@endif
@endsection
