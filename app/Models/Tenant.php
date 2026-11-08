<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
final class Tenant extends Model {
    use HasUuids;
    protected $guarded = ['id'];
    public function memberships() { return $this->hasMany(TenantMembership::class); }
}
