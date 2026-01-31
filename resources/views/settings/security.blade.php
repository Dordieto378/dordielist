{{-- resources/views/settings/security.blade.php --}}
@extends('layouts.app')

@section('content')
@php $user = auth()->user(); @endphp
  <div class="bg-gray-100 mb-[50px] pt-[100px]">
    <div class="max-w-[1320px] mx-auto px-4 sm:px-6 lg:px-8 flex space-x-6 text-sm">

      {{-- LEFT: Shared settings sidebar --}}
      @include('settings.partials.sidebar')

      {{-- RIGHT: 2FA management --}}
      <main class="w-3/4">
        <div class="bg-white shadow-md rounded-lg overflow-hidden">
          <div class="p-8">
            <div class="flex items-center justify-between">
              <h1 class="text-2xl font-bold text-red-600">Two-Factor Authentication</h1>
            </div>

            {{-- Enable --}}
            @if (! $user->two_factor_secret)
              <div class="mt-6 space-y-4">
                <form method="POST" action="{{ route('two-factor.enable') }}">
                  @csrf
                  <button
                    type="submit"
                    class="inline-block w-full px-4 py-2 bg-[#08875b] text-white rounded hover:bg-emerald-700"
                  >
                    Enable Two-Factor Authentication
                  </button>
                </form>
              </div>
            @endif

            @if (
              session('status') === 'two-factor-authentication-enabled'
              || ($user->two_factor_secret && $user->two_factor_confirmed)
            )
              <h2 class="text-xl font-semibold text-gray-800 mt-8 mb-4">Security Settings</h2>
              <hr class="my-4" />
            @endif

            {{-- Confirm --}}
            @if (session('status') === 'two-factor-authentication-enabled' || $errors->has('code'))
              <p class="text-red-600 mb-4 font-medium">
                Scan the QR code below and enter the code from your authenticator app.
              </p>

              <div class="mb-6">
                {!! $user->twoFactorQrCodeSvg() !!}
              </div>

              <form method="POST" action="{{ route('two-factor.confirm') }}" class="space-y-4">
                @csrf

                @error('code')
                  <div class="mb-4 p-4 bg-red-100 border-red-200 text-red-800 border rounded">
                    {{ $message }}
                  </div>
                @enderror

                <label for="code" class="block text-red-600 font-medium mb-1">
                  One-Time Code
                </label>
                <input
                  id="code"
                  name="code"
                  type="text"
                  required
                  autofocus
                  autocomplete="one-time-code"
                  value="{{ old('code') }}"
                  class="w-full text-sm bg-gray-100 focus:outline-none focus:ring-1 focus:ring-flatRed border-gray-200 text-gray-800 font-medium p-2 rounded-md border"
                />

                <button
                  type="submit"
                  class="inline-block w-full px-4 py-2 bg-[#08875b] text-white rounded hover:bg-emerald-700"
                >
                  Confirm
                </button>
              </form>
            @endif

            {{-- Recovery & disable --}}
            @if ($user->two_factor_secret && $user->two_factor_confirmed)
              <h3 class="text-lg font-medium text-red-600 mb-4 mt-8">
                Recovery Codes
              </h3>
              <div class="grid grid-cols-2 gap-2 mb-6">
                @foreach ($user->recoveryCodes() as $code)
                  <code class="block bg-gray-100 p-2 rounded text-gray-800">{{ $code }}</code>
                @endforeach
              </div>

              <form method="POST" action="{{ route('two-factor.disable') }}">
                @csrf
                @method('DELETE')
                <button
                  type="submit"
                  class="inline-block w-full px-4 py-2 bg-[#08875b] text-white rounded hover:bg-emerald-700"
                >
                  Disable Two-Factor Authentication
                </button>
              </form>
            @endif

          </div>
        </div>
      </main>
    </div>
  </div>
@endsection
