@if(session('error'))
    <div class="mx-auto mt-6 w-full max-w-[1320px] px-4 sm:px-6 lg:px-8">
        <div class="app-alert app-alert-error">
            {{ session('error') }}
        </div>
    </div>
@endif
