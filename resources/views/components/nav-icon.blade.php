@props(['name'])
@php
    $paths = [
        'dashboard' => 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
        'contracts' => 'M6 3h9l3 3v15H6z M14 3v5h4 M9 12h6 M9 16h6',
        'reports' => 'M4 20h16 M7 16V9 M12 16V4 M17 16v-5',
        'calendar' => 'M4 5h16v16H4z M8 3v4 M16 3v4 M4 10h16 M8 14h2 M14 14h2',
        'settings' => 'M4 7h16 M4 17h16 M9 4v6 M15 14v6',
        'account' => 'M16 7a4 4 0 1 1-8 0a4 4 0 0 1 8 0 M4 21v-2a8 8 0 0 1 16 0v2',
        'catalogs' => 'M3 21h18 M5 21V9 M10 21V9 M14 21V9 M19 21V9 M3 9h18L12 3z',
        'audit' => 'M6 3h12v18H6z M9 7h6 M9 11h6 M9 15h3',
        'users' => 'M15 8a3 3 0 1 1-6 0a3 3 0 0 1 6 0 M5 21v-3a7 7 0 0 1 14 0v3 M18 5a3 3 0 0 1 0 6 M21 17v4',
    ];
@endphp
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $paths[$name]??$paths['contracts'] }}"/></svg>
