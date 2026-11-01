<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A business error with a stable machine code, rendered in the spec 14 §14.1 format.
 * The message is looked up in lang/en/errors.php using the code.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $fields
     * @param  array<string, mixed>  $meta  extra, non-sensitive data for the client
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status = 422,
        public readonly array $fields = [],
        public readonly array $meta = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($errorCode);
    }
}
