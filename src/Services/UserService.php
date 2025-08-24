<?php
namespace App\Services;

use App\Contracts\UserRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Models\User;

class UserService
{
    public function __construct(private UserRepositoryInterface $repo) {}

    public function list(int $perPage, array $filters = []): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, 100));
        return $this->repo->paginate($perPage, $filters);
    }

    public function get(int $id): ?User
    {
        return $this->repo->find($id);
    }

    public function create(array $data): User
    {
        return $this->repo->create($data);
    }

    public function update(int $id, array $data): ?User
    {
        $u = $this->repo->find($id);
        if (!$u) return null;
        return $this->repo->update($u, $data);
    }

    public function delete(int $id): bool
    {
        $u = $this->repo->find($id);
        return $u ? $this->repo->delete($u) : false;
    }

    public function emailTaken(string $email, ?int $exceptId = null): bool
    {
        return $this->repo->emailExists($email, $exceptId);
    }
}
