@extends('layouts.app')

@section('content')
@php $user = auth()->user(); @endphp
<div class="min-h-[908px] flex items-center justify-center bg-gray-100 mt-[100px] mb-6">
  <div class="max-w-md w-full bg-white shadow-md rounded-lg p-8">
    @if (! $user->two_factor_secret)
    <h2 class="text-2xl font-semibold text-gray-800 mb-4">
      Security Settings
    </h2>
    <hr class="my-4" />
      <form method="POST" action="{{ route('two-factor.enable') }}">
        @csrf
        <button
          type="submit"
          class="inline-block w-full px-4 py-2 bg-[#08875b] text-white rounded hover:bg-emerald-700"
        >
          Enable Two-Factor Authentication
        </button>
      </form>
    @endif
@if (
     session('status') === 'two-factor-authentication-enabled'
     || ($user->two_factor_secret && $user->two_factor_confirmed)
   )
    <h2 class="text-2xl font-semibold text-gray-800 mb-4">
      Security Settings
    </h2>
    <hr class="my-4" />
@endif
    {{-- 2) Confirm (secret exists but not yet confirmed) --}}
@if (session('status') === 'two-factor-authentication-enabled' || $errors->has('code'))
      <p class="mb-4 text-red-600" style="font-weight: 400; text-transform: none;">
        Scan de QR code bellow.
      </p>

      <div class="mb-6">
        {!! $user->twoFactorQrCodeSvg() !!}
      </div>

      <form method="POST" action="{{ route('two-factor.confirm') }}" class="space-y-4">
        @csrf

        @error('code')
          <div class="app-alert app-alert-error mb-4">
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

    {{-- 3) Recovery Codes & Disable (only after confirmed) --}}
    @if ($user->two_factor_secret && $user->two_factor_confirmed)
      <h3 class="text-lg font-medium text-red-600 mb-4">
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
@endsection
