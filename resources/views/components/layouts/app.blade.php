@props(['title' => 'Início'])
<x-layouts.auth :title="$title">
    <a class="skip-link" href="#main-content">Ir para o conteúdo</a>
    <script>
        try { document.documentElement.dataset.sidebarCollapsed = String(localStorage.getItem('contracts.sidebar-collapsed') === 'true'); } catch {}
    </script>
    <div class="workspace">
        <aside class="workspace-nav" data-collapsed="true">
            <div class="nav-brand-row">
                <a class="workspace-brand" href="{{ route('dashboard') }}" aria-label="Contracts — Início"><x-brand-mark/><span class="brand-name">Contracts</span></a>
                <button class="sidebar-toggle" type="button" aria-label="Recolher barra lateral" title="Recolher barra lateral" aria-expanded="true" aria-controls="workspace-menu" data-sidebar-toggle><span class="sidebar-toggle-mark" aria-hidden="true"><x-brand-mark/></span><span class="sidebar-toggle-icon"><x-nav-icon name="sidebar"/></span></button>
                <button class="menu-toggle" type="button" aria-label="Abrir menu de navegação" aria-expanded="false" aria-controls="workspace-menu" data-menu-toggle><x-nav-icon name="menu"/></button>
            </div>
            <span class="brand-subtitle">Gestão de contratos</span>
            <nav id="workspace-menu" aria-label="Navegação principal">
                <div class="nav-section-label">Acompanhamento</div>
                @foreach([
                    ['dashboard', 'dashboard', 'Início', 'dashboard'],
                    ['contracts.index', 'contracts.*', 'Contratos', 'contracts'],
                    ['suppliers.index', 'suppliers.*', 'Fornecedores', 'suppliers'],
                    ['reports.index', 'reports.*', 'Relatórios', 'reports'],
                    ['calendar.index', 'calendar.*', 'Calendário e alertas', 'calendar'],
                ] as [$route, $pattern, $label, $icon])
                    <a href="{{ route($route) }}" aria-label="{{ $label }}" data-nav-label="{{ $label }}" @class(['nav-link', 'selected' => request()->routeIs($pattern)]) @if(request()->routeIs($pattern)) aria-current="page" @endif><x-nav-icon :name="$icon"/><span>{{ $label }}</span></a>
                @endforeach
                @if(auth()->user()->isAdmin())
                    <div class="nav-section-label">Administração</div>
                    @foreach([
                        ['users.index', 'users.*', 'Usuários', 'users'],
                        ['catalogs.index', 'catalogs.*', 'Órgãos e permissões', 'catalogs'],
                        ['contract-types.index', 'contract-types.*', 'Requisitos por tipo', 'settings'],
                        ['administration.index', 'administration.*', 'Auditoria e conciliação', 'audit'],
                    ] as [$route, $pattern, $label, $icon])
                        <a href="{{ route($route) }}" aria-label="{{ $label }}" data-nav-label="{{ $label }}" @class(['nav-link', 'selected' => request()->routeIs($pattern)]) @if(request()->routeIs($pattern)) aria-current="page" @endif><x-nav-icon :name="$icon"/><span>{{ $label }}</span></a>
                    @endforeach
                @endif
            </nav>
            <div class="nav-bottom">
                <a href="{{ route('account.edit') }}" aria-label="Minha conta" data-nav-label="Minha conta" @class(['nav-link', 'selected' => request()->routeIs('account.*')]) @if(request()->routeIs('account.*')) aria-current="page" @endif><x-nav-icon name="account"/><span>Minha conta</span></a>
                <div class="nav-account">
                    <span class="account-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                    <div><strong>{{ auth()->user()->username }}</strong><span>{{ auth()->user()->isAdmin() ? 'Administrador' : 'Usuário' }}</span></div>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout-button" type="submit" aria-label="Sair" title="Sair"><x-nav-icon name="logout"/></button></form>
                </div>
            </div>
        </aside>
        <main class="workspace-content" id="main-content" tabindex="-1">
            <header class="workspace-top">
                <span>Gestão de contratos <span class="breadcrumb-divider" aria-hidden="true">/</span><strong>{{ $title }}</strong></span>
                <div class="topbar-account"><span class="workspace-date"><x-nav-icon name="calendar"/>{{ today()->format('d/m/Y') }}</span><a class="account-avatar" href="{{ route('account.edit') }}" aria-label="Abrir minha conta" title="{{ auth()->user()->name }}">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</a></div>
            </header>
            @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="notice notice-error" role="alert"><strong>Confira os dados informados.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            {{ $slot }}
        </main>
    </div>
</x-layouts.auth>
