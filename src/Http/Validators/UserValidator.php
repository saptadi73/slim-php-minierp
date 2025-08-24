<?php
namespace App\Http\Validators;

use Respect\Validation\Validator as v;

class UserValidator
{
    public static function forCreate(array $data): array
    {
        $errors = [];

        if (!v::key('name', v::stringType()->notEmpty()->length(2, 100))->validate($data)) {
            $errors['name'] = 'Nama wajib, 2–100 karakter.';
        }

        if (!v::key('email', v::email())->validate($data)) {
            $errors['email'] = 'Email tidak valid.';
        }

        return $errors;
    }

    public static function forUpdate(array $data): array
    {
        $errors = [];
        if (array_key_exists('name', $data) && !v::stringType()->notEmpty()->length(2, 100)->validate($data['name'])) {
            $errors['name'] = 'Nama 2–100 karakter.';
        }
        if (array_key_exists('email', $data) && !v::email()->validate($data['email'])) {
            $errors['email'] = 'Email tidak valid.';
        }
        return $errors;
    }
}
