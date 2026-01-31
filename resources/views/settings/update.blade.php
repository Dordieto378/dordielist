{{-- resources/views/settings/users.blade.php --}}
@extends('layouts.app')

@section('content')
    @if(optional(auth()->user()->role)->role === 'Admin')
        <div class="bg-gray-100 mb-[50px] pt-[100px]">
            <div class="max-w-[1320px] mx-auto px-4 sm:px-6 lg:px-8 flex space-x-6 text-sm">

                @include('settings.partials.sidebar')

                <main class="w-3/4">
                    <div class="bg-white shadow-md rounded-lg overflow-hidden p-8">
                        <h1 class="text-2xl font-bold text-red-600 mb-4">Update List</h1>
                        <form method="POST" action="{{ route('anilist.sync') }}" class="mt-3">
                            @csrf
                            <button type="submit" class="inline-block w-full px-4 py-2 flatGreen text-white rounded-[0.19rem] mt-2 hover:bg-emerald-700 text-center">
                                Update Anilist
                            </button>
                        </form>
                        <form method="POST" action="{{ route('doujin.sync') }}" class="mt-3">
                            @csrf
                            <button type="submit" class="inline-block w-full px-4 py-2 flatGreen text-white rounded-[0.19rem] mt-2 hover:bg-emerald-700 text-center">
                                Update Doujin
                            </button>
                        </form>
                        <form method="POST" action="{{ route('vndb.sync') }}" class="mt-3">
                            @csrf
                            <button type="submit" class="inline-block w-full px-4 py-2 flatGreen text-white rounded-[0.19rem] mt-2 hover:bg-emerald-700 text-center">
                                Update Vndb
                            </button>
                        </form>

                    </div>
                </main>
            </div>
        </div>
    @endif
@endsection
