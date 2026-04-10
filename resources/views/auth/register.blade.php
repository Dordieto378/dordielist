@extends('layouts.app')

@section('content')
<style>
  .register-input {
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
  }

  .register-input:focus {
    border-color: #dc2626 !important;
    box-shadow: none !important;
  }
</style>
<div class="min-h-[1032px] bg-gray-100 flex items-start justify-center pt-[115px]">
  <div class="w-full max-w-[500px] grid gap-4">
    <div
      role="tablist"
      aria-orientation="horizontal"
      class="grid grid-cols-2 p-2 text-gray-600 text-base font-semibold"
    >
      <button
        id="tab-login"
        role="tab"
        data-state="inactive"
        aria-selected="false"
        class="py-2 px-3 text-gray-900 border-b-[1px] border-gray-300 focus:outline-none"
      >
        <a href="{{ route('login') }}">Login</a>
      </button>
      <button
        id="tab-register"
        role="tab"
        data-state="active"
        aria-selected="true"
        class="py-2 px-3 text-gray-900 border-b-[4px] border-gray-300 focus:outline-none"
      >
        <a href="{{ route('register') }}">Create Account</a>
      </button>
    </div>

    <div class="relative -mt-1">
      {{-- ► FLASH MESSAGE (only errors, in red) --}}
      @if(session('status'))
        @php
          $bg = session('status_color') === 'red'
                ? 'bg-red-100 border-red-200 text-red-800'
                : 'bg-gray-100 border-gray-200 text-gray-800';
        @endphp
        <div class="app-alert mb-4 {{ session('status_color') === 'red' ? 'app-alert-error' : 'app-alert-neutral' }}">
          {{ session('status') }}
        </div>
      @endif

      <form
        method="POST"
        action="{{ route('register') }}"
        novalidate
        class="bg-white rounded-lg shadow-sm p-8 flex flex-col gap-6 min-h-[540px]"
      >
        @csrf

        <div class="flex flex-col gap-2 w-[420px] mx-auto mt-1">
          <h1 class="text-xl font-bold text-gray-900">Welcome to DORDIELIST</h1>
          <p class="text-base text-gray-800 font-medium">
            Sign up below to start watching and reading.
          </p>
        </div>

        <div class="mt-4 flex flex-col gap-8">
          {{-- Email --}}
          <div class="flex flex-col gap-2 w-[420px] mx-auto">
            <label for="email" class="text-base text-gray-800 font-medium">Email</label>
            <input
              id="email"
              name="email"
              type="text"
              value="{{ old('email') }}"
              required
              inputmode="email"
              autocomplete="email"
              autocapitalize="off"
              spellcheck="false"
              class="register-input auth-input block min-h-10 w-full appearance-none rounded-md border {{ $errors->has('email') ? 'border-red-500' : 'border-gray-200' }} bg-gray-100 px-3 py-2 text-sm leading-5 text-gray-800 font-medium focus:outline-none"
              @if($errors->has('email')) aria-invalid="true" @endif
            >
            @error('email')
              <p class="app-inline-error">{{ $message }}</p>
            @enderror
          </div>

          {{-- Username --}}
          <div class="flex flex-col gap-2 w-[420px] mx-auto">
            <label for="username" class="text-base text-gray-800 font-medium">Username</label>
            <input
              id="username"
              name="username"
              type="text"
              value="{{ old('username') }}"
              required
              autocomplete="username"
              class="register-input auth-input block min-h-10 w-full appearance-none rounded-md border {{ $errors->has('username') ? 'border-red-500' : 'border-gray-200' }} bg-gray-100 px-3 py-2 text-sm leading-5 text-gray-800 font-medium focus:outline-none"
              @if($errors->has('username')) aria-invalid="true" @endif
            >
            @error('username')
              <p class="app-inline-error">{{ $message }}</p>
            @enderror
          </div>

          {{-- Password --}}
          <div class="flex flex-col gap-2 w-[420px] mx-auto">
            <label for="password" class="text-base text-gray-800 font-medium">Password</label>
            <input
              id="password"
              name="password"
              type="password"
              required
              autocomplete="new-password"
              class="register-input auth-input block min-h-10 w-full appearance-none rounded-md border {{ $errors->has('password') ? 'border-red-500' : 'border-gray-200' }} bg-gray-100 px-3 py-2 text-sm leading-5 text-gray-800 font-medium focus:outline-none"
              @if($errors->has('password')) aria-invalid="true" @endif
            >
            @error('password')
              <p class="app-inline-error">{{ $message }}</p>
            @enderror
          </div>

          {{-- Confirm Password --}}
          <div class="flex flex-col gap-2 w-[420px] mx-auto">
            <label for="password_confirmation" class="text-base text-gray-800 font-medium">Confirm Password</label>
            <input
              id="password_confirmation"
              name="password_confirmation"
              type="password"
              required
              autocomplete="new-password"
              class="register-input auth-input block min-h-10 w-full appearance-none rounded-md border {{ $errors->has('password_confirmation') ? 'border-red-500' : 'border-gray-200' }} bg-gray-100 px-3 py-2 text-sm leading-5 text-gray-800 font-medium focus:outline-none"
              @if($errors->has('password_confirmation')) aria-invalid="true" @endif
            >
            @error('password_confirmation')
              <p class="app-inline-error">{{ $message }}</p>
            @enderror
          </div>

          {{-- Age confirmation checkbox --}}
          <div class="flex flex-col gap-2 w-[420px] mx-auto">
            <label for="terms" class="flex items-start gap-2 cursor-pointer">
              <input type="checkbox" id="terms" name="terms" required class="sr-only peer">
              <span class="mr-1 inline-block h-[15px] w-4 rounded border border-flatRed bg-white transition peer-checked:bg-flatRed peer-checked:border-red-600 group-hover:bg-gray-100 flex items-center justify-center">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-black hidden peer-checked:block" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                  </svg>
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-6">
                      <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                  </svg>
              </span>
              <div class="grid gap-1.5 mt-[-2px] leading-none">
                <span class="text-sm font-medium text-gray-800">
                  I affirm that I am at least 18 years of age
                </span>
                <p class="text-xs font-medium text-gray-500">
                  or the age of majority in the jurisdiction I am accessing this Website from
                  and I agree to the
                  <a href="/terms" target="_blank" class="text-blue-600 hover:underline">Terms of Service</a>
                  and
                  <a href="/privacy" target="_blank" class="text-blue-600 hover:underline">Privacy Policy</a>.
                </p>
              </div>
            </label>
            @error('terms')
              <p class="app-inline-error">{{ $message }}</p>
            @enderror
          </div>

          {{-- Submit --}}
          <div class="flex flex-col gap-4 w-[420px] mx-auto">
            <button
              type="submit"
              class="h-9 w-full rounded-md bg-[#08875b] text-white text-sm font-medium hover:bg-emerald-700 focus:outline-none"
            >
              Create Account
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>
@endsection
