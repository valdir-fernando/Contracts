@props(['title' => 'Início', 'contractEditor' => false])
@php($summaryInHeader = $contractEditor && isset($contractSummary))
<x-layouts.auth :title="$title">
    <a class="skip-link" href="#main-content">Ir para o conteúdo</a>
    <script>
        if (window.self !== window.top && window.name === 'contracts-supplier-catalog') {
            document.documentElement.dataset.supplierCatalog = 'true';
        }
        if (window.self !== window.top && window.name === 'contracts-amendments-panel') {
            document.documentElement.dataset.amendmentsPanel = 'true';
        }
        try { document.documentElement.dataset.sidebarCollapsed = String(localStorage.getItem('contracts.sidebar-collapsed') === 'true'); } catch {}
    </script>
    <div @class(['workspace', 'workspace-contract-editor' => $contractEditor])>
        <aside class="workspace-nav" data-collapsed="true">
            <div class="nav-brand-row">
                <a class="workspace-brand" href="{{ route('dashboard') }}" aria-label="Contracts — Início"><x-brand-mark/><span class="brand-name">Contracts</span></a>
                <button class="sidebar-toggle" type="button" aria-label="Recolher barra lateral" title="Recolher barra lateral" aria-expanded="true" aria-controls="workspace-menu" data-sidebar-toggle><span class="sidebar-toggle-mark" aria-hidden="true"><x-brand-mark/></span><span class="sidebar-toggle-icon"><x-nav-icon name="sidebar"/></span></button>
                <button class="menu-toggle" type="button" aria-label="Abrir menu de navegação" aria-expanded="false" aria-controls="workspace-menu" data-menu-toggle><x-nav-icon name="menu"/></button>
            </div>
            <nav id="workspace-menu" aria-label="Navegação principal">
                <div class="nav-section-label nav-administration-title">Menu de navegação</div>
                @foreach([
                    ['dashboard', ['dashboard', 'atas.dashboard'], 'Início', 'dashboard'],
                    ['contracts.index', 'contracts.*', 'Contratos', 'contracts'],
                    ['publication.index', 'publication.*', 'Publicação', 'document-extract'],
                    ['reports.index', 'reports.*', 'Relatórios', 'reports'],
                    ['calendar.index', 'calendar.*', 'Calendário e alertas', 'calendar'],
                ] as [$route, $pattern, $label, $icon])
                    @if($route === 'contracts.index')
                        <a href="{{ route($route) }}" aria-label="{{ $label }}" data-nav-label="{{ $label }}" @class(['nav-link', 'selected' => request()->routeIs($pattern)]) @if(request()->routeIs($pattern)) aria-current="page" @endif><x-nav-icon :name="$icon"/><span>{{ $label }}</span></a>
                        <details class="nav-catalogs" @if(request()->routeIs('catalogs.list', 'catalogs.inspectors', 'catalogs.contract-stewards', 'catalogs.managers', 'catalogs.budgets.*', 'suppliers.*', 'funds.*', 'users.*')) open @endif>
                            <summary class="nav-link" aria-label="Cadastros" data-nav-label="Cadastros"><x-nav-icon name="catalogs"/><span>Cadastros</span></summary>
                            <div class="nav-submenu">
                                @if(auth()->user()->isAdmin())
                                    <a href="{{ route('users.index') }}" aria-label="Usuários" data-nav-label="Usuários" @class(['nav-link', 'selected' => request()->routeIs('users.*')]) @if(request()->routeIs('users.*')) aria-current="page" @endif><x-nav-icon name="users"/><span>Usuários</span></a>
                                @endif
                                    @foreach(['inspector' => 'Fiscal_Contrato', 'contract-steward' => 'Gestor_Contrato', 'manager-fund' => 'Gestor_Fundo'] as $kind => $name)
                                        @if(auth()->user()->canAccessCatalog($kind))
                                        <a class="nav-link" href="{{ route('catalogs.list', $kind) }}" @if(request()->routeIs('catalogs.list', 'catalogs.inspectors', 'catalogs.contract-stewards', 'catalogs.managers') && request()->route('kind') === $kind) aria-current="page" @endif><x-nav-icon name="users"/><span>{{ $name }}</span></a>
                                        @endif
                                    @endforeach
                                    @if(auth()->user()->canAccessCatalog('fund'))
                                    <a class="nav-link" href="{{ route('funds.index') }}" @if(request()->routeIs('funds.*')) aria-current="page" @endif><x-nav-icon name="catalogs"/><span>Fundo</span></a>
                                    @endif
                                    @if(auth()->user()->canAccessCatalog('department'))
                                    <a class="nav-link" href="{{ route('catalogs.index') }}#departments"><x-nav-icon name="catalogs"/><span>Secretaria</span></a>
                                    @endif
                                    @if(auth()->user()->canAccessCatalog('budget'))
                                    <a class="nav-link" href="{{ route('catalogs.budgets.create') }}" @if(request()->routeIs('catalogs.budgets.*')) aria-current="page" @endif><x-nav-icon name="reports"/><span>Dotação</span></a>
                                    @endif
                                <a class="nav-link" href="{{ route('suppliers.index') }}" @if(request()->routeIs('suppliers.*')) aria-current="page" @endif><x-nav-icon name="suppliers"/><span>Fornecedor</span></a>
                            </div>
                        </details>
                    @else
                    <a href="{{ route($route) }}" aria-label="{{ $label }}" data-nav-label="{{ $label }}" @class(['nav-link', 'selected' => request()->routeIs($pattern)]) @if(request()->routeIs($pattern)) aria-current="page" @endif><x-nav-icon :name="$icon"/><span>{{ $label }}</span></a>
                    @endif
                @endforeach
                @if(auth()->user()->isAdmin())
                    <div class="nav-section-label nav-administration-title">Administração</div>
                    @foreach([
                        ['catalogs.index', ['catalogs.index', 'catalogs.show'], 'Órgãos e permissões', 'catalogs'],
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
                    <x-account-avatar :user="auth()->user()"/>
                    <div><strong>{{ auth()->user()->username }}</strong><span>{{ auth()->user()->roleLabel() }}</span></div>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout-button" type="submit" aria-label="Sair" title="Sair"><x-nav-icon name="logout"/></button></form>
                </div>
            </div>
            <script data-sidebar-position>
                (() => {
                    const menu = document.getElementById('workspace-menu');
                    const catalogs = menu.querySelector('.nav-catalogs');
                    const key = 'contracts.menu-position';
                    try {
                        const saved = JSON.parse(sessionStorage.getItem(key));
                        if (saved && Number.isFinite(saved.top) && typeof saved.catalogsOpen === 'boolean') {
                            catalogs.open = saved.catalogsOpen;
                            menu.scrollTop = saved.top;
                        }
                    } catch {}
                    const save = () => {
                        try { sessionStorage.setItem(key, JSON.stringify({ top: menu.scrollTop, catalogsOpen: catalogs.open })); } catch {}
                    };
                    menu.addEventListener('scroll', save, { passive: true });
                    menu.addEventListener('click', save);
                    catalogs.addEventListener('toggle', save);
                })();
            </script>
        </aside>
        <main class="workspace-content" id="main-content" tabindex="-1" @if($contractEditor) data-contract-editor @endif>
            <header @class(['workspace-top', 'workspace-top-compact' => request()->routeIs('contracts.create'), 'workspace-top-contract' => $summaryInHeader])>
                @if(request()->routeIs('dashboard', 'atas.dashboard'))
                <nav class="header-modules" aria-label="Módulos do sistema">
                    <a class="header-module-button" href="{{ route('dashboard') }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif><x-nav-icon name="contracts"/><span>CONTRATOS</span></a>
                    <a class="header-module-button" href="{{ route('atas.dashboard') }}" @if(request()->routeIs('atas.dashboard')) aria-current="page" @endif><x-nav-icon name="documents"/><span>ATAS</span></a>
                </nav>
                @endif
                @isset($headerBack)<div class="fund-header-group">{{ $headerBack }}@endisset
                @if($selectedFund = request()->attributes->get('selected_fund'))
                    <span class="fund-breadcrumb"><strong class="selected-fund-name"{{ $summaryInHeader ? ' data-fit-fund-name' : '' }}>@isset($fundName){{ $fundName }}@else{{ $selectedFund['name'] }}@endisset</strong>@unless(request()->routeIs('dashboard', 'atas.dashboard') || $summaryInHeader) <span class="breadcrumb-divider" aria-hidden="true">/</span>{{ $title }}@endunless</span>
                @else
                    <span>Gestão de contratos @unless($summaryInHeader)<span class="breadcrumb-divider" aria-hidden="true">/</span><strong>{{ $title }}</strong>@endunless</span>
                @endif
                @isset($fundSupplier){{ $fundSupplier }}@endisset
                @isset($headerBack)</div>@endisset
                @unless($summaryInHeader)<div class="topbar-account"><span class="workspace-date"><x-nav-icon name="calendar"/>{{ today()->format('d/m/Y') }}</span><a class="account-avatar-link" href="{{ route('account.edit') }}" aria-label="Abrir minha conta" title="{{ auth()->user()->name }}"><x-account-avatar :user="auth()->user()"/></a></div>@endunless
                @if($summaryInHeader)<div class="contract-context-heading">{{ $contractSummary }}</div>@endif
                @isset($headerClose){{ $headerClose }}@endisset
            </header>
            @unless($contractEditor)
            @isset($contractSummary)<div class="contract-context-row">@endisset
            @isset($contractSummary)@unless($summaryInHeader)<div class="contract-context-heading">{{ $contractSummary }}</div>@endunless</div>@endisset
            @endunless
            @if($contractEditor && session('contract_saved') && session('status'))
                <dialog id="contract-save-success" class="catalog-modal product-item-warning" role="alertdialog" aria-labelledby="contract-save-success-title" aria-describedby="contract-save-success-message" data-modal-auto-open>
                    <div class="modal-header"><h2 id="contract-save-success-title">Salvar contrato</h2></div>
                    <p id="contract-save-success-message">{{ session('status') }}</p>
                    <div class="form-actions action-toolbar"><button type="button" class="section-tab" data-modal-close autofocus>OK</button></div>
                </dialog>
            @elseif(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="notice notice-error" role="alert"><strong>Confira os dados informados.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            {{ $slot }}
        </main>
    </div>
</x-layouts.auth>
