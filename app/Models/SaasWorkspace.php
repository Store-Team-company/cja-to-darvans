<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SaasWorkspace extends Model
{
    protected $table = 'saas_workspaces';

    protected $fillable = ['name'];

    protected static function booted(): void
    {
        static::creating(function (self $workspace): void {
            $workspace->public_id ??= (string) Str::uuid();
        });
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'saas_workspace_memberships', 'workspace_id', 'user_id')
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    public function products(): HasMany
    {
        return $this->hasMany(SaasProduct::class, 'workspace_id');
    }

    public function stores(): HasMany
    {
        return $this->hasMany(SaasBotbleStore::class, 'workspace_id');
    }

    public function cjConnections(): HasMany
    {
        return $this->hasMany(SaasCjConnection::class, 'workspace_id');
    }
}
