@props(['name'])
@php
    $paths = [
        'dashboard' => 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
        'contracts' => 'M6 3h9l3 3v15H6z M14 3v5h4 M9 12h6 M9 16h6',
        'document-extract' => 'M6 3h9l3 3v15H6z M14 3v5h4 M9 11h6 M9 14h6 M9 17h3',
        'document-publication' => 'M3 10v5h4l9 4V6l-9 4H3z M7 15l2 6h3l-2-5 M19 9l2-1 M19 16l2 1',
        'document-termination' => 'M6 3h9l3 3v15H6z M14 3v5h4 M9 12l6 6 M15 12l-6 6',
        'reports' => 'M4 20h16 M7 16V9 M12 16V4 M17 16v-5',
        'calendar' => 'M4 5h16v16H4z M8 3v4 M16 3v4 M4 10h16 M8 14h2 M14 14h2',
        'settings' => 'M4 7h16 M4 17h16 M9 4v6 M15 14v6',
        'account' => 'M16 7a4 4 0 1 1-8 0a4 4 0 0 1 8 0 M4 21v-2a8 8 0 0 1 16 0v2',
        'catalogs' => 'M3 21h18 M5 21V9 M10 21V9 M14 21V9 M19 21V9 M3 9h18L12 3z',
        'audit' => 'M6 3h12v18H6z M9 7h6 M9 11h6 M9 15h3',
        'users' => 'M15 8a3 3 0 1 1-6 0a3 3 0 0 1 6 0 M5 21v-3a7 7 0 0 1 14 0v3 M18 5a3 3 0 0 1 0 6 M21 17v4',
        'suppliers' => 'M5 9h14 M6 9V8a6 6 0 0 1 12 0v1 M11 3v4 M13 3v4 M8 9v2a4 4 0 0 0 8 0V9 M4 22v-2a5 5 0 0 1 5-5l3 4 3-4a5 5 0 0 1 5 5v2 M12 19v3',
        'plus' => 'M12 5v14 M5 12h14',
        'save' => 'M5 3h12l4 4v14H3V3h2 M7 3v6h10V3 M7 21v-8h10v8',
        'edit' => 'm16 3 5 5 M4 15 16 3l5 5L9 20l-6 1 1-6Z',
        'trash' => 'M3 6h18 M9 6V3h6v3 M5 6l1 15h12l1-15 M10 10v7 M14 10v7',
        'search' => 'M16 10a6 6 0 1 1-12 0a6 6 0 0 1 12 0 M15 15l5 5',
        'filter' => 'M3 4h18l-7 8v7l-4 2v-9L3 4Z',
        'arrow' => 'M5 12h14 M14 7l5 5-5 5',
        'check' => 'M5 12l4 4L19 6',
        'clock' => 'M21 12a9 9 0 1 1-18 0a9 9 0 0 1 18 0 M12 7v5l3 2',
        'warning' => 'M12 3L2 21h20L12 3Z M12 9v5 M12 17h.01',
        'logout' => 'M9 4H4v16h5 M9 12h12 M17 8l4 4-4 4',
        'sidebar' => 'M4 4h16v16H4z M9 4v16 M6.5 8v8',
        'chevron-left' => 'M14 7l-5 5 5 5',
        'menu' => 'M4 6h16 M4 12h16 M4 18h16',
        'shield' => 'M12 3l8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z M8 12l3 3 5-6',
    ];
@endphp
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $paths[$name]??$paths['contracts'] }}"/></svg>
