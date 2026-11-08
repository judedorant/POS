<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
final class TenantMembership extends Model {
    use HasUuids;
    protected $guarded = ['id'];
    public function tenant() { return $this->belongsTo(Tenant::class); }
}
