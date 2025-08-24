<?php
namespace App\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Models\User;

interface UserRepositoryInterface
{
    public function paginate(int $perPage, array $filters = [], string $orderBy = 'id', string $direction = 'desc'): LengthAwarePaginator;
    public function find(int $id): ?User;
    public function create(array $data): User;
    public function update(User $user, array $data): User;
    public function delete(User $user): bool;
    public function emailExists(string $email, ?int $exceptId = null): bool;
}
