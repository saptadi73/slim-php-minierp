<?php
namespace App\Services;
use Carbon\Carbon;

use App\Models\AuthToken;

class TokenService
{
    public function __construct(private int $refreshTtlDays) {}

    /** Create & store hashed token, returns plain token (once). */
    public function issue(string $type, int $userId, int $ttlSeconds, array $meta = []): string
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');
        $hash = hash('sha256', $plain);
        AuthToken::create([
            'user_id' => $userId,
            'type' => $type,
            'token_hash' => $hash,
            'expires_at' => Carbon::now()->addSeconds($ttlSeconds),
            'meta' => $meta,
        ]);
        return $plain;
    }

    public function verify(string $type, int $userId, string $plain): ?AuthToken
    {
        $hash = hash('sha256', $plain);
        $tok = AuthToken::where(compact('user_id','type'))->where('token_hash',$hash)->first();
        if (!$tok) return null;
        if ($tok->revoked_at || $tok->expires_at->isPast()) return null;
        return $tok;
    }

    public function revoke(AuthToken $tok): void
    {
        $tok->revoked_at = Carbon::now();
        $tok->save();
    }

    public function revokeAll(int $userId, string $type): int
    {
        return AuthToken::where('user_id',$userId)->where('type',$type)->update(['revoked_at'=>Carbon::now()]);
    }
}
