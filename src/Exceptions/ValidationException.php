<?php
namespace App\Exceptions;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    public function __construct(public array $errors = [], string $message = 'Validation failed', int $code = 0, ?\Throwable $prev = null)
    {
        parent::__construct($message, $code, $prev);
    }
}
