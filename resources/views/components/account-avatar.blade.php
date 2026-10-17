@props(['user'])
<span {{ $attributes->class(['account-avatar']) }} aria-hidden="true">
    @if($user->avatar_path)
        <img src="{{ route('account.avatar.show') }}" alt="" width="256" height="256">
    @else
        {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
    @endif
</span>
