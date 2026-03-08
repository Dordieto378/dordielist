{{-- resources/views/settings/users.blade.php --}}
@extends('layouts.app')

@section('content')
@if(optional(auth()->user()->role)->role === 'Admin')
  @php
    $openEditUserId = session('open_edit_user_id');
    $editOldValues = [
      'username' => old('username'),
      'email' => old('email'),
      'role_id' => old('role_id'),
      'status' => old('status'),
    ];
  @endphp
  <div class="bg-gray-100 mb-[50px] pt-[100px]">
    <div class="max-w-[1320px] mx-auto px-4 sm:px-6 lg:px-8 flex space-x-6 text-sm">

      {{-- LEFT: shared sidebar --}}
      @include('settings.partials.sidebar')

      {{-- RIGHT: Users content --}}
      <main class="w-3/4">
        <div class="bg-white shadow-md rounded-lg overflow-hidden p-8">
          <h1 class="text-2xl font-bold text-red-600 mb-4">Users</h1>

          @if(session('status'))
            @php
              $bg = session('status_color') === 'red'
                      ? 'bg-red-100 border-red-200 text-red-800'
                      : (
                          session('status_color') === 'green'
                          ? 'bg-green-100 border-green-200 text-green-800'
                          : 'bg-gray-100 border-gray-200 text-gray-800'
                      );
            @endphp
            <div class="mt-4 mb-4 p-4 {{ $bg }} border rounded">
              {{ session('status') }}
            </div>
          @endif

          {{-- Table wrapper --}}
          <div class="overflow-x-auto">
            <table class="min-w-full table-auto bg-white">
              <thead>
                <tr class="border-b border-gray-200">
                  <th class="px-6 py-3 font-medium text-left text-gray-800 uppercase text-center tracking-wider">ID</th>
                  <th class="px-6 py-3 font-medium text-left text-gray-800 uppercase text-center tracking-wider">Username</th>
                  <th class="px-6 py-3 font-medium text-left text-gray-800 uppercase text-center tracking-wider">Email</th>
                  <th class="px-6 py-3 font-medium text-left text-gray-800 uppercase text-center tracking-wider">Role</th>
                  <th class="px-6 py-3 font-medium text-left text-gray-800 uppercase text-center tracking-wider">Status</th>
                  <th class="px-6 py-3 font-medium text-left text-gray-800 uppercase text-center tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-200 font-medium text-center">
                @foreach($users as $user)
                  <tr>
                    <td class="px-6 py-4 whitespace-nowrap text-gray-800">
                      {{ $user->user_id }}
                    </td>

                    <td class="px-6 py-4 whitespace-nowrap text-gray-800">
                      {{ $user->username }}
                    </td>

                    <td class="px-6 py-4 whitespace-nowrap text-gray-800">
                      {{ $user->email }}
                    </td>

                    <td class="px-6 py-4 whitespace-nowrap text-gray-800">
                      {{ optional($user->role)->role ?? '-' }}
                    </td>

                    <td class="px-6 py-4 whitespace-nowrap">
                      @if($user->status === 'active')
                        <span class="inline-flex px-2 text-sm font-semibold leading-5 rounded-full bg-green-100 text-green-800">
                          Active
                        </span>
                      @elseif($user->status === 'not_active')
                        <span class="inline-flex px-2 text-sm font-semibold leading-5 rounded-full bg-yellow-100 text-yellow-800">
                          Not Active
                        </span>
                      @elseif($user->status === 'banned')
                        <span class="inline-flex px-2 text-sm font-semibold leading-5 rounded-full bg-red-100 text-red-800">
                          Banned
                        </span>
                      @else
                        <span class="inline-flex px-2 text-sm font-semibold leading-5 rounded-full bg-gray-100 text-gray-800">
                          {{ ucfirst($user->status) }}
                        </span>
                      @endif
                    </td>

                    <td class="px-6 py-4 whitespace-nowrap">
                      <button
                        type="button"
                        class="text-blue-600 hover:text-blue-800 hover:underline openEditUserModal"
                        data-user-id="{{ $user->user_id }}"
                        data-username="{{ $user->username }}"
                        data-email="{{ $user->email }}"
                        data-role-id="{{ $user->role_id }}"
                        data-status="{{ $user->status }}"
                        data-update-url="{{ route('settings.users.update', $user) }}"
                      >
                        Edit
                      </button>
                    </td>
                  </tr>
                @endforeach

                @if($users->isEmpty())
                  <tr>
                    <td colspan="6" class="px-6 py-4 whitespace-nowrap text-center text-gray-800">
                      No users found.
                    </td>
                  </tr>
                @endif
              </tbody>
            </table>
          </div>
        </div>
      </main>
    </div>
  </div>

  <div
    id="editUserModal"
    class="fixed inset-0 flex items-start pt-[130px] justify-center bg-black bg-opacity-50 hidden z-50"
  >
    <div
      class="relative bg-white p-4 text-left shadow-2xl w-[800px] rounded-lg space-y-6 overflow-auto"
      role="dialog"
      aria-modal="true"
    >
      <div class="flex justify-between items-start pb-4 pt-2 border-b border-gray-200">
        <h2 class="text-lg font-bold text-gray-800 pl-4">Edit User</h2>
        <button type="button" class="text-gray-400 hover:text-gray-900 pr-4" aria-label="Close">
          <span class="sr-only">Close</span>
          <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </button>
      </div>

      <div class="space-y-4">
        <form id="editUserForm" class="space-y-4" method="POST" action="">
          @csrf
          @method('PUT')

          <input type="hidden" id="editUserId" name="editing_user_id" value="">

          <label class="block relative" for="editUsername">
            <span class="block mb-2 label-text text-red-600 font-medium pl-4">Username</span>
            <input
              type="text"
              id="editUsername"
              name="username"
              class="w-[735px] ml-4 rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
              required
            />
          </label>

          <label class="block relative" for="editEmail">
            <span class="block mb-2 label-text text-red-600 font-medium pl-4">Email</span>
            <input
              type="email"
              id="editEmail"
              name="email"
              class="w-[735px] ml-4 rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
              required
            />
          </label>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 px-4">
            <label class="block relative" for="editRole">
              <span class="block mb-2 label-text text-red-600 font-medium">Role</span>
              <select
                id="editRole"
                name="role_id"
                class="w-full rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
                required
              >
                @foreach($roles as $role)
                  <option value="{{ $role->role_id }}">{{ $role->role }}</option>
                @endforeach
              </select>
            </label>

            <label class="block relative" for="editStatus">
              <span class="block mb-2 label-text text-red-600 font-medium">Status</span>
              <select
                id="editStatus"
                name="status"
                class="w-full rounded-md border border-gray-200 px-3 py-2 text-base bg-gray-100 focus:outline-none focus:ring-[0.2rem] focus:ring-red-600 text-gray-800 font-medium"
                required
              >
                <option value="active">Active</option>
                <option value="not_active">Not Active</option>
                <option value="banned">Banned</option>
              </select>
            </label>
          </div>

          <div class="flex items-center gap-3 pl-4 pb-2">
            <button type="submit" class="flatGreen transition-200 text-white px-5 py-3 rounded">
              Save Changes
            </button>
            <button
              id="cancelEditUserModal"
              type="button"
              class="px-5 py-3 rounded border border-gray-200 text-gray-700 hover:bg-gray-100 transition-200"
            >
              Cancel
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const modal = document.getElementById('editUserModal');
      const form = document.getElementById('editUserForm');
      const openButtons = document.querySelectorAll('.openEditUserModal');
      const closeBtn = modal?.querySelector('button[aria-label="Close"]');
      const cancelBtn = document.getElementById('cancelEditUserModal');
      const idInput = document.getElementById('editUserId');
      const usernameInput = document.getElementById('editUsername');
      const emailInput = document.getElementById('editEmail');
      const roleInput = document.getElementById('editRole');
      const statusInput = document.getElementById('editStatus');

      const oldUserId = @json($openEditUserId);
      const oldValues = @json($editOldValues);

      if (!modal || !form) return;

      const showModal = () => modal.classList.remove('hidden');
      const hideModal = () => modal.classList.add('hidden');

      const openFromButton = (btn, useOldValues = false) => {
        if (!btn) return;

        idInput.value = btn.dataset.userId || '';
        usernameInput.value = btn.dataset.username || '';
        emailInput.value = btn.dataset.email || '';
        roleInput.value = btn.dataset.roleId || '';
        statusInput.value = btn.dataset.status || '';
        form.action = btn.dataset.updateUrl || '';

        if (useOldValues) {
          if (oldValues.username !== null) usernameInput.value = oldValues.username;
          if (oldValues.email !== null) emailInput.value = oldValues.email;
          if (oldValues.role_id !== null) roleInput.value = String(oldValues.role_id);
          if (oldValues.status !== null) statusInput.value = oldValues.status;
        }

        showModal();
      };

      openButtons.forEach((btn) => {
        btn.addEventListener('click', () => openFromButton(btn));
      });

      closeBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        hideModal();
      });

      cancelBtn?.addEventListener('click', hideModal);

      modal.addEventListener('click', (e) => {
        if (e.target === modal) hideModal();
      });

      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
          hideModal();
        }
      });

      if (oldUserId) {
        const failedButton = document.querySelector(`.openEditUserModal[data-user-id="${oldUserId}"]`);
        if (failedButton) {
          openFromButton(failedButton, true);
        }
      }
    });
  </script>
@endif
@endsection
