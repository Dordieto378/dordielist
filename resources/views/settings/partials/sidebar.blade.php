{{-- resources/views/settings/partials/sidebar.blade.php --}}
<aside class="w-1/4">
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
      <li>
        <a href="{{ route('settings.addDoujin') }}"
           class="block pl-4 pr-4 py-3 rounded-md
                  {{ request()->routeIs('settings.addDoujin') ? 'bg-red-600 text-white' : 'text-gray-700' }}">
          Add Doujin
        </a>
      </li>
    @endif
  </ul>
</aside>
