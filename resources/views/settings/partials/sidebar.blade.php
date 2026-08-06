{{-- resources/views/settings/partials/sidebar.blade.php --}}
<aside class="w-1/4">
  @php $isViewer = optional(auth()->user()?->role)->role === 'Viewer'; @endphp
  <ul class="space-y-[-3px]">
    <li>
      <a href="{{ route('settings.profile.edit') }}"
         class="block pl-4 pr-4 py-3 rounded-md
                {{ request()->routeIs('settings.profile.edit') ? 'bg-red-600 text-white' : 'text-gray-700' }}">
        Account
      </a>
    </li>
    @if(optional(auth()->user()->role)->role === 'Admin')
      <li>
        <a href="{{ route('settings.users') }}"
           class="block pl-4 pr-4 py-3 rounded-md
                  {{ request()->routeIs('settings.users') ? 'bg-red-600 text-white' : 'text-gray-700' }}">
          Users
        </a>
      </li>
    @endif
    @unless($isViewer)
      <li>
        <a href="{{ route('settings.api') }}"
           class="block pl-4 pr-4 py-3 rounded-md
                  {{ request()->routeIs('settings.api') ? 'bg-red-600 text-white' : 'text-gray-700' }}">
          API
        </a>
      </li>
      <li>
        <a href="{{ route('settings.doujin-authors') }}"
           class="block pl-4 pr-4 py-3 rounded-md
                  {{ request()->routeIs('settings.doujin-authors*') ? 'bg-red-600 text-white' : 'text-gray-700' }}">
          Doujin Artists
        </a>
      </li>
    @endunless
    <li>
      <a href="{{ route('settings.security') }}"
         class="block pl-4 pr-4 py-3 rounded-md
                {{ request()->routeIs('settings.security') ? 'bg-red-600 text-white' : 'text-gray-700' }}">
        2FA
      </a>
    </li>
  </ul>
</aside>
