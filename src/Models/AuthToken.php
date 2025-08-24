<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon; // ⬅️ tambahkan ini

class AuthToken extends Model
{
    protected $table = 'auth_tokens';
    protected $fillable = ['user_id','type','token_hash','expires_at','revoked_at','meta'];
    protected $casts = ['expires_at'=>'datetime','revoked_at'=>'datetime','meta'=>'array'];

    public function scopeActive($q) {
        return $q->whereNull('revoked_at')
                 ->where('expires_at', '>', Carbon::now()); // ⬅️ ganti now()
    }
}
