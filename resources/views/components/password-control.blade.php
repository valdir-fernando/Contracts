@props(['id'])
<div class="password-control">
    {{ $slot }}
    <button class="password-toggle" type="button" aria-label="Mostrar senha" aria-controls="{{ $id }}" aria-pressed="false" data-password-toggle>
        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
    </button>
</div>
