@extends('layouts.app')

@section('content')
<div class="min-h-[1032px] flex items-center justify-center bg-gray-100 py-[200px]">
  <div class="max-w-md w-full bg-white shadow-md rounded-lg p-8">
    {{-- Title --}}
    <h2 class="text-2xl font-semibold text-gray-800 mb-4">
      Please Confirm Your Password
    </h2>

    {{-- (No subtitle here; errors will show in red) --}}
    <form method="POST" action="{{ url('/user/confirm-password') }}" class="space-y-4">
      @csrf
      @error('password')
      <div class="mb-4 p-4 bg-red-100 border-red-200 text-red-800 border rounded">
        {{ $message }}
      </div>
      @enderror
      <div>
        <label for="password" class="block text-red-600 font-medium mb-1">Password</label>
        <input
          id="password"
          name="password"
          type="password"
          required
          autocomplete="current-password"
          class="w-full text-sm 
                    bg-gray-100 focus:outline-none focus:ring-1 focus:ring-flatRed
                    border-gray-200 text-gray-800 font-medium p-2 text-gray-800 rounded-md border"
        />
      </div>

      <button
        type="submit"
        class="inline-block w-full px-4 py-2 bg-[#08875b] text-white rounded hover:bg-emerald-700"
      >
        Confirm Password
      </button>
    </form>
  </div>
</div>
@endsection
