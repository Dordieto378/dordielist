@extends('layouts.app')

@section('content')
<div class="min-h-[1032px] bg-gray-100 flex items-start justify-center pt-[110px]">
  <div class="w-full max-w-[500px] grid gap-4">
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
      {{-- ► FLASH MESSAGE (only errors, in red) --}}
      @if(session('status'))
        @php
          // Only “red” is relevant here—no green success in login.
          $bg = session('status_color') === 'red'
                ? 'bg-red-100 border-red-200 text-red-800'
                : 'bg-gray-100 border-gray-200 text-gray-800';
        @endphp
        <div class="app-alert mb-4 {{ session('status_color') === 'red' ? 'app-alert-error' : 'app-alert-neutral' }}">
          {{ session('status') }}
        </div>
      @endif

      @if($errors->any())
        <div class="app-alert app-alert-error mb-4">
          {{ $errors->first() }}
        </div>
      @endif

      <form
        method="POST"
        action="{{ route('login') }}"
        id="pane-login"
        class="bg-white rounded-lg shadow-sm p-8 flex flex-col gap-6 min-h-[460px]"
      >
        @csrf

        <div class="flex flex-col gap-2 w-[420px] mx-auto mt-1">
          <h1 class="text-xl font-bold text-gray-900">Log in to get right back into it</h1>
          <p class="text-base text-gray-800 font-medium">Welcome back! Please enter your details.</p>
        </div>

        <div class="mt-4 flex flex-col gap-8">
          <div class="flex flex-col gap-2 w-[420px] mx-auto">
            <label for="email" class="text-base text-gray-700 font-medium">Email</label>
            <input
              id="email"
              name="email"
              type="email"
              autocomplete="email"
              class="h-9 w-full rounded-md border px-3 py-1 text-sm 
                    bg-gray-100 focus:outline-none focus:ring-1 focus:ring-flatRed
                    border-gray-200 text-gray-800 font-medium"
            >
          </div>
          <div class="flex flex-col gap-2 w-[420px] mx-auto">
            <label for="password" class="text-base text-gray-700 font-medium">Password</label>
            <input
              id="password"
              name="password"
              type="password"
              autocomplete="current-password"
              class="h-9 w-full rounded-md border px-3 py-1 text-sm 
                    bg-gray-100 focus:outline-none focus:ring-1 focus:ring-flatRed
                    border-gray-200 text-gray-800 font-medium"
            >
          </div>

          <div class="flex flex-col gap-4">
            <button
              type="submit"
              class="h-9 w-[420px] mx-auto rounded-md bg-[#08875b] text-white text-sm font-medium hover:bg-emerald-700 focus:outline-none"
            >
              Login
            </button>
            <a
              href=""
              class="h-9 w-[420px] mx-auto flex items-center justify-center rounded-md text-sm text-gray-600 focus:outline-none"
            >
              Forgot Password?
            </a>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>
@endsection
