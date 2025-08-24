<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Model
{
    use SoftDeletes;

    protected $table = 'users';
    protected $fillable = ['name','email','password'];
    protected $hidden = ['password'];
    public $timestamps = true;
    protected $dates =['deleted_at'];

    public function roles() { return $this->belongsToMany(Role::class, 'role_user'); }
    public function hasRole(string $role): bool { return $this->roles()->where('name',$role)->exists(); }
}
