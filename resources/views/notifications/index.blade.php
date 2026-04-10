@extends('layouts.app')

@section('content')
<div class="bg-gray-100 mb-[50px] pt-[100px] pb-16">
    <div class="max-w-[1320px] mx-auto px-4 sm:px-6 lg:px-8">
        <main class="mx-auto w-3/4">
            @if($notifications->isEmpty())
                <div class="rounded-md bg-white p-8 text-center text-gray-600 shadow-sm">
                    No notifications yet.
                </div>
            @else
                <div class="space-y-4">
                    @foreach($notifications as $notification)
                        @php
                            $content = trim((string) ($notification->body ?? ''));
                            $title = trim((string) ($notification->title ?? 'Notification'));
                        @endphp

                        <a
                            href="{{ $notification->url ?: '#' }}"
                            class="relative flex min-h-[96px] overflow-hidden rounded-md bg-white text-gray-800 shadow-sm transition hover:shadow-md"
                            @if(!empty($notification->show_unread_marker))
                                style="border-right: 12px solid #dc2626;"
                            @endif
                        >
                            @if($notification->image_url)
                                <span class="h-[96px] w-[74px] shrink-0 overflow-hidden bg-gray-100">
                                    <img src="{{ $notification->image_url }}" alt="" class="h-full w-full object-cover">
                                </span>
                            @else
                                <span class="flex h-[96px] w-[74px] shrink-0 items-center justify-center bg-gray-100 text-red-600">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0a3 3 0 01-6 0" />
                                    </svg>
                                </span>
                            @endif

                            <span class="min-w-0 flex-1 px-4 py-3">
                                <span class="block truncate text-[15px] font-bold text-gray-900">{{ $title }}</span>
                                @if($content !== '')
                                    <span class="mt-1 block text-[13px] font-medium normal-case leading-5 text-gray-600">{{ $content }}</span>
                                @endif
                                @if($notification->notified_at)
                                    <span class="mt-1.5 block text-[11px] font-medium normal-case text-gray-400">
                                        {{ $notification->notified_at->diffForHumans() }}
                                    </span>
                                @endif
                            </span>
                        </a>
                    @endforeach
                </div>

                @if($hasMore)
                    <div class="mt-8 text-center">
                        <a
                            id="notifications-show-more"
                            href="{{ route('notifications.index', ['limit' => $limit + 20]) }}"
                            class="text-base font-bold text-red-600 hover:underline"
                        >
                            Show more
                        </a>
                    </div>
                @endif
            @endif
        </main>
    </div>
</div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const scrollStorageKey = 'notifications-index-scroll-y';
            const showMoreLink = document.getElementById('notifications-show-more');
            const savedScrollY = window.sessionStorage.getItem(scrollStorageKey);
            const unreadIds = @json(array_values($visibleUnreadNotificationIds));

            if (savedScrollY !== null) {
                window.sessionStorage.removeItem(scrollStorageKey);

                window.requestAnimationFrame(function () {
                    window.scrollTo(0, parseInt(savedScrollY, 10) || 0);
                });
            }

            if (showMoreLink) {
                showMoreLink.addEventListener('click', function () {
                    window.sessionStorage.setItem(scrollStorageKey, String(window.scrollY));
                });
            }

            if (unreadIds.length === 0) {
                return;
            }

            window.setTimeout(function () {
                fetch(@json(route('notifications.read-visible')), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ ids: unreadIds }),
                    credentials: 'same-origin',
                }).catch(function () {});
            }, 300);
        });
    </script>
@endpush
