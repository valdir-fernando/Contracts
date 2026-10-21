<x-layouts.auth :title="$title">
    <main class="budget-panel-content">
        @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
        {{ $slot }}
    </main>
</x-layouts.auth>
