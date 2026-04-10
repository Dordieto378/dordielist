{{-- resources/views/auth/register_pending.blade.php --}}
@extends('layouts.app')

@section('content')
<div class="min-h-[650px] flex items-center justify-center bg-gray-100 py-16">
  <div class="max-w-md w-full bg-white shadow-md rounded-lg p-8">
    <h2 class="text-2xl font-semibold text-gray-900 mb-4">Registration Received</h2>
    <p class="text-gray-700 mb-6 font-medium">
      Thank you for registering, <strong>{{ $username ?? old('username') ?? 'New User' }}</strong>.<br>
      Please wait for an administrator to confirm your account. 
      You will receive an email once your account is activated.
    </p>
    <div class="p-4 bg-yellow-100 border border-yellow-200 text-yellow-800 rounded mb-4 font-medium">
      <p>
        <strong>Note:</strong> You cannot log in until your account is activated.
      </p>
    </div>
    <a href="{{ url('/') }}"
       class="inline-block w-full text-center px-4 py-2 bg-[#08875b] text-white rounded hover:bg-emerald-700">
      Back to Homepage
    </a>
  </div>
</div>
@endsection
