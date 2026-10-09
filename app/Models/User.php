<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = ['name', 'username', 'email', 'password', 'reference_user_id', 'user_type', 'fund', 'department'];

    protected $hidden = ['password', 'remember_token', 'session_version'];

    protected $attributes = ['is_active' => true, 'session_version' => 0, 'user_type' => 'Usuario'];

    public function scopes(): BelongsToMany
    {
        return $this->belongsToMany(AccessScope::class);
    }

    public function isAdmin(): bool
    {
        return $this->is_active && ! $this->trashed() && $this->user_type === 'Administrador';
    }

    public function canAccessScope(int $scopeId): bool
    {
        return $this->is_active && ! $this->trashed()
            && ($this->isAdmin() || $this->scopes()->whereKey($scopeId)->exists());
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'session_version' => 'integer',
        ];
    }
}
