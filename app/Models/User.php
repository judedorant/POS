<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
final class User extends Authenticatable
{
    use HasUuids;
    protected $fillable = ['name', 'email', 'password'];
    protected $hidden = ['password', 'remember_token'];
    protected function casts(): array { return ['password' => 'hashed', 'disabled_at' => 'datetime']; }
    public function tenants() { return $this->belongsToMany(Tenant::class, 'tenant_memberships')->withPivot('role', 'status'); }
}
