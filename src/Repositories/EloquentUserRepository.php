<?php
namespace App\Repositories;

use App\Contracts\UserRepositoryInterface;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentUserRepository implements UserRepositoryInterface
{
    public function paginate(int $perPage, array $filters = [], string $orderBy = 'id', string $direction = 'desc'): LengthAwarePaginator
    {
        $q = User::query();

        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $q->where(function($qq) use ($s) {
                $qq->where('name', 'like', "%{$s}%")
                   ->orWhere('email', 'like', "%{$s}%");
            });
        }

        return $q->orderBy($orderBy, $direction)->paginate($perPage)->withQueryString();
    }

    public function find(int $id): ?User
    {
        return User::find($id);
    }

    public function create(array $data): User
    {
        return User::create($data);
    }

    public function update(User $user, array $data): User
    {
        $user->fill($data)->save();
        return $user;
    }

    public function delete(User $user): bool
    {
        return (bool) $user->delete();
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        $q = User::where('email', $email);
        if ($exceptId) $q->where('id', '!=', $exceptId);
        return $q->exists();
    }
}
