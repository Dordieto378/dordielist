{{-- resources/views/admin/user_confirmed.blade.php --}}
@extends('layouts.app')

@section('content')
  <div class="min-h-screen flex items-center justify-center bg-gray-50 p-8">
    <div class="max-w-md w-full bg-white rounded-lg shadow-md p-6">
      {{-- Heading --}}
      <h2 class="text-2xl font-bold text-red-600 mb-4">User Activated</h2>

      {{-- Customized message --}}
      <p class="text-gray-800 mb-2">
        You have successfully activated:
      </p>
      <ul class=" mb-4 text-gray-800 font-medium">
        <li><strong>Username:</strong> {{ $user->username }}</li>
        <li><strong>User ID:</strong> {{ $user->user_id }}</li>
        <li><strong>Email:</strong> {{ $user->email }}</li>
      </ul>
      <div class="flex space-x-4">
        <a href="{{ url('/') }}"
           class="flex-grow text-center px-4 py-2 bg-[#08875b] text-white rounded hover:bg-emerald-700">
          Return Home
        </a>
      </div>
    </div>
  </div>
@endsection
