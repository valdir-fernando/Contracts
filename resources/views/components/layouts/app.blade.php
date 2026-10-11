@props(['title' => 'Início'])
<x-layouts.auth :title="$title">
    <a class="skip-link" href="#main-content">Ir para o conteúdo</a>
    <div class="workspace">
        <aside class="workspace-nav">
            <div class="nav-brand-row"><a class="workspace-brand" href="{{ route('dashboard') }}"><span class="brand-symbol" aria-hidden="true">C</span>Contracts</a><button class="menu-toggle secondary-button" type="button" aria-expanded="true" aria-controls="workspace-menu" data-menu-toggle>Menu</button></div>
            <div class="nav-section-label">Workspace</div>
            <nav id="workspace-menu" aria-label="Navegação principal">
                @php
                    $links = [
                        ['dashboard','dashboard','Início','dashboard'], ['contracts.index','contracts.*','Contratos','contracts'],
                        ['reports.index','reports.*','Relatórios','reports'], ['calendar.index','calendar.*','Calendário e alertas','calendar'],
                    ];
                    if(auth()->user()->isAdmin()) {
                        $links = array_merge($links, [
                            ['users.index','users.*','Usuários','users'], ['catalogs.index','catalogs.*','Órgãos e permissões','catalogs'],
                            ['contract-types.index','contract-types.*','Requisitos por tipo','settings'], ['administration.index','administration.*','Auditoria e conciliação','audit'],
                        ]);
                    }
                    $links[] = ['account.edit','account.*','Minha conta','account'];
                @endphp
                @foreach($links as [$route,$pattern,$label,$icon])
                    <a href="{{ route($route) }}" @class(['nav-link','selected'=>request()->routeIs($pattern)]) @if(request()->routeIs($pattern))aria-current="page"@endif><x-nav-icon :name="$icon"/><span>{{ $label }}</span></a>
                @endforeach
            </nav>
            <div class="nav-account"><span class="account-avatar" aria-hidden="true">{{ mb_substr(auth()->user()->name,0,1) }}</span><div><strong>{{ auth()->user()->username }}</strong><span>{{ auth()->user()->isAdmin() ? 'Administrador' : 'Usuário' }}</span></div><form method="POST" action="{{ route('logout') }}">@csrf<button class="secondary-button" type="submit">Sair</button></form></div>
        </aside>
        <main class="workspace-content" id="main-content" tabindex="-1">
            <header class="workspace-top"><span>Gestão de contratos <span class="breadcrumb-divider" aria-hidden="true">/</span> <strong>{{ $title }}</strong></span><span class="workspace-date">{{ today()->format('d/m/Y') }}</span></header>
            @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="notice notice-error" role="alert"><strong>Confira os dados informados.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            {{ $slot }}
        </main>
    </div>
</x-layouts.auth>
