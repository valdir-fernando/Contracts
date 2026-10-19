<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    public const MASTER = 'Administrador Master';

    public const ROLES = [self::MASTER, 'Administrador', 'Usuario'];

    public const CATALOGS = ['inspector' => 'Fiscal_Contrato', 'contract-steward' => 'Gestor_Contrato',
        'manager-fund' => 'Gestor_Fundo', 'fund' => 'Fundo', 'department' => 'Secretaria', 'budget' => 'Dotação', 'supplier' => 'Fornecedor'];

    public const DELEGATABLE_PERMISSIONS = self::CATALOGS + ['contract-issuance' => 'Emissão de contratos'];

    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = ['name', 'username', 'email', 'password', 'reference_user_id', 'user_type', 'fund', 'department'];

    protected $hidden = ['password', 'remember_token', 'session_version', 'avatar_path', 'avatar_sha256'];

    protected $attributes = ['is_active' => true, 'session_version' => 0, 'user_type' => 'Usuario'];

    public function scopes(): BelongsToMany
    {
        return $this->belongsToMany(AccessScope::class);
    }

    public function isAdmin(): bool
    {
        return $this->is_active && ! $this->trashed() && in_array($this->user_type, [self::MASTER, 'Administrador'], true);
    }

    public function isMaster(): bool
    {
        return $this->is_active && ! $this->trashed() && $this->user_type === self::MASTER;
    }

    public function roleLabel(): string
    {
        return $this->user_type === 'Usuario' ? 'Usuário' : (string) $this->user_type;
    }

    public function canAccessCatalog(string $kind): bool
    {
        return array_key_exists($kind, self::CATALOGS) && $this->is_active && ! $this->trashed()
            && ($this->isAdmin() || in_array($kind, $this->catalog_permissions ?? [], true));
    }

    public function canIssueContracts(): bool
    {
        return $this->is_active && ! $this->trashed()
            && ($this->isAdmin() || in_array('contract-issuance', $this->catalog_permissions ?? [], true));
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
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'session_version' => 'integer',
            'expiration_alert_enabled' => 'boolean',
            'expiration_alert_days' => 'integer',
            'catalog_permissions' => 'array',
        ];
    }
}
