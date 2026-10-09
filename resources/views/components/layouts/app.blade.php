@props(['title' => 'Início'])
<x-layouts.auth :title="$title">
    <div class="workspace">
        <aside class="workspace-nav">
            <a class="workspace-brand" href="{{ route('dashboard') }}">Contracts</a>
            <nav aria-label="Navegação principal">
                <a href="{{ route('dashboard') }}" @class(['nav-link', 'selected' => request()->routeIs('dashboard')])>Início</a>
                @can('viewAny', \App\Models\Contract::class)<a href="{{ route('contracts.index') }}" @class(['nav-link', 'selected' => request()->routeIs('contracts.*')])>Contratos</a>@endcan
                @can('viewAny', \App\Models\User::class)<a href="{{ route('users.index') }}" @class(['nav-link', 'selected' => request()->routeIs('users.*')])>Usuários</a>@endcan
            </nav>
            <div class="nav-account"><strong>{{ auth()->user()->username }}</strong><span>{{ auth()->user()->isAdmin() ? 'Administrador' : 'Usuário' }}</span><form method="POST" action="{{ route('logout') }}">@csrf<button class="secondary-button" type="submit">Sair</button></form></div>
        </aside>
        <main class="workspace-content">
            <header class="workspace-top"><span>Gestão de contratos</span><span>{{ $title }}</span></header>
            @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="notice notice-error" role="alert"><strong>Confira os dados informados.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            {{ $slot }}
        </main>
    </div>
</x-layouts.auth>
