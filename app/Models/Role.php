<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class Role extends Model {
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected function casts(): array { return ['permissions' => 'array']; }
}
