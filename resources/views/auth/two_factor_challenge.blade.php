@extends('layouts.app')

@section('content')
<div class="min-h-[1032px] bg-gray-100 flex items-start justify-center pt-[110px]">
  <div class="w-full max-w-[500px] grid gap-4">
    {{-- Tabs (same as login) --}}
    <div
      role="tablist"
      aria-orientation="horizontal"
      class="grid grid-cols-2 p-2 text-gray-600 text-base font-semibold "
    >
      <button
        id="tab-login"
        role="tab"
        data-state="active"
        aria-selected="true"
        class="py-2 px-3 text-gray-900 border-b-[4px] border-gray-300 mb-[-2px] focus:outline-none"
      >
        <a href="{{ route('login') }}">Login</a>
      </button>
      <button
        id="tab-register"
        role="tab"
        data-state="inactive"
        aria-selected="false"
        class="py-2 px-3 text-gray-900 border-b-[1px] border-gray-300 mb-[-1px] focus:outline-none"
      >
        <a href="{{ route('register') }}">Create Account</a>
      </button>
    </div>

    <div class="relative -mt-1">
      {{-- Flash error --}}
      @error('code')
        <div class="mb-4 p-4 bg-red-100 border-red-200 text-red-800 border rounded">
          {{ $message }}
        </div>
      @enderror

      <form
        method="POST"
        action="{{ url('/two-factor-challenge') }}"
        class="bg-white rounded-lg shadow-sm p-8 flex flex-col gap-6 min-h-[460px]"
      >
        @csrf

        <div class="flex flex-col gap-2 w-[420px] mx-auto mt-1">
          <h1 class="text-xl font-bold text-gray-900">Two-Factor Authentication</h1>
          <p class="text-base text-gray-800 font-medium">Enter the 6-digit code from your authenticator app.</p>
        </div>

        <div class="mt-4 flex flex-col gap-8">
          <div class="flex flex-col gap-2 w-[420px] mx-auto">
            <label for="code" class="text-base text-gray-700 font-medium">One-Time Code</label>
            <input
              id="code"
              name="code"
              type="text"
              inputmode="numeric"
              pattern="[0-9]*"
              autocomplete="one-time-code"
              required
              autofocus
              class="h-9 w-full rounded-md border px-3 py-1 text-sm 
                    bg-gray-100 focus:outline-none focus:ring-1 focus:ring-flatRed
                    border-gray-200 text-gray-800 font-medium"
            >
          </div>

          <div class="flex flex-col gap-4 mt-[100px]">
            <button
              type="submit"
              class="h-9 w-[420px] mx-auto rounded-md bg-[#08875b] text-white text-sm font-medium hover:bg-emerald-700 focus:outline-none"
            >
              Verify
            </button>
            <a
              href="{{ route('login') }}"
              class="h-9 w-[420px] mx-auto flex items-center justify-center rounded-md text-sm text-gray-600 focus:outline-none"
            >
              Back to login
            </a>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>
@endsection
