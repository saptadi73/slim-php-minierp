<?php
namespace App\Services;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthService
{
    public function __construct(
        private string $secret,
        private int $accessTtlMin,
        private TokenService $tokens
    ) {}

    public function register(string $name, string $email, string $password): User
    {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $u = User::create(['name'=>$name,'email'=>$email,'password'=>$hash]);
        // default role user
        $this->ensureDefaultRoles($u);
        return $u;
    }

    public function attempt(string $email, string $password, array $meta=[]): ?array
    {
        $user = User::where('email',$email)->first();
        if (!$user || !password_verify($password, $user->password)) return null;
        return $this->issuePair($user, $meta);
    }

    public function issuePair(User $user, array $meta=[]): array
    {
        $access = $this->issueAccessToken($user);
        $refreshPlain = $this->tokens->issue('refresh', $user->id, $this->refreshTtlSeconds(), $meta);
        return [
            'access_token' => $access['jwt'],
            'access_expires_at' => $access['exp'],
            'refresh_token' => $refreshPlain,
        ];
    }

    public function decode(string $jwt): ?array
    {
        try { return (array)JWT::decode($jwt, new Key($this->secret,'HS256')); }
        catch (\Throwable) { return null; }
    }

    public function issueAccessToken(User $user): array
    {
        $now = time();
        $exp = $now + ($this->accessTtlMin * 60);
        $roles = $user->roles()->pluck('name')->all();
        $payload = ['sub'=>$user->id,'email'=>$user->email,'roles'=>$roles,'iat'=>$now,'exp'=>$exp];
        return ['jwt'=>JWT::encode($payload,$this->secret,'HS256'),'exp'=>$exp];
    }

    public function refresh(User $user, string $refreshPlain, array $meta=[]): ?array
    {
        $tok = $this->tokens->verify('refresh', $user->id, $refreshPlain);
        if (!$tok) return null;
        // rotation: revoke old, issue new
        $this->tokens->revoke($tok);
        return $this->issuePair($user, $meta);
    }

    public function logout(User $user, string $refreshPlain): bool
    {
        $tok = $this->tokens->verify('refresh', $user->id, $refreshPlain);
        if (!$tok) return false;
        $this->tokens->revoke($tok); return true;
    }

    public function logoutAll(User $user): int
    {
        return $this->tokens->revokeAll($user->id, 'refresh');
    }

    public function refreshTtlSeconds(): int { return (int)($_ENV['JWT_REFRESH_TTL_DAYS'] ?? 30) * 86400; }

    private function ensureDefaultRoles(User $u): void
    {
        $role = \App\Models\Role::firstOrCreate(['name'=>'user'],['label'=>'Default user']);
        $u->roles()->syncWithoutDetaching([$role->id]);
    }
}
