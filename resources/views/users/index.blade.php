<x-layouts.app title="Usuários">
    <div class="page-heading"><div><h1>Usuários</h1><p>Gerencie contas, perfis e órgãos autorizados.</p></div><a class="primary-button compact" href="{{ route('users.create') }}">Novo usuário</a></div>
    <section class="surface">
        <form class="filter-bar" method="GET" action="{{ route('users.index') }}">
            <div><label for="q">Buscar</label><input id="q" name="q" value="{{ request('q') }}" placeholder="Nome ou usuário"></div>
            <div><label for="role">Perfil</label><select id="role" name="role"><option value="">Todos</option><option value="Administrador" @selected(request('role') === 'Administrador')>Administrador</option><option value="Usuario" @selected(request('role') === 'Usuario')>Usuário</option></select></div>
            <div><label for="status">Situação</label><select id="status" name="status"><option value="">Todos os cadastrados</option><option value="active" @selected(request('status') === 'active')>Ativos</option><option value="inactive" @selected(request('status') === 'inactive')>Inativos</option><option value="deleted" @selected(request('status') === 'deleted')>Excluídos</option></select></div>
            <div><label for="scope">Fundo / secretaria</label><select id="scope" name="scope"><option value="">Todos</option>@foreach($scopes as $scope)<option value="{{ $scope->id }}" @selected((string) request('scope') === (string) $scope->id)>{{ $scope->label }}</option>@endforeach</select></div>
            <div><label for="sort">Ordenar por</label><select id="sort" name="sort"><option value="name">Nome</option><option value="username" @selected(request('sort') === 'username')>Usuário</option></select></div>
            <button class="secondary-button" type="submit">Filtrar</button><a class="text-link" href="{{ route('users.index') }}">Limpar</a>
        </form>
        <div class="table-scroll"><table class="data-table"><thead><tr><th>Nome / usuário</th><th>Perfil</th><th>Órgãos autorizados</th><th>Situação</th><th><span class="sr-only">Ações</span></th></tr></thead><tbody>
            @forelse($users as $user)
                <tr><td><strong>{{ $user->name }}</strong><small>{{ $user->username }}</small></td><td>{{ $user->user_type === 'Administrador' ? 'Administrador' : 'Usuário' }}</td><td class="scope-cell">@if($user->user_type === 'Administrador')Todos os órgãos @else @forelse($user->scopes as $scope)<small>{{ $scope->label }}</small>@empty<span class="muted">Sem vínculo</span>@endforelse @endif</td><td><span @class(['badge', 'badge-muted' => ! $user->is_active || $user->trashed()])>{{ $user->trashed() ? 'Excluído' : ($user->is_active ? 'Ativo' : 'Inativo') }}</span></td><td>@if($user->trashed())<form method="POST" action="{{ route('users.restore', $user) }}">@csrf<button class="secondary-button" type="submit">Restaurar e ativar</button></form>@else<a class="secondary-button" href="{{ route('users.edit', $user) }}">Editar</a>@endif</td></tr>
            @empty<tr><td colspan="5" class="empty-state">Nenhum usuário encontrado para estes filtros.</td></tr>@endforelse
        </tbody></table></div>
        <div class="table-footer"><span>{{ $users->total() }} {{ $users->total() === 1 ? 'usuário' : 'usuários' }}</span>{{ $users->links() }}</div>
    </section>
</x-layouts.app>
