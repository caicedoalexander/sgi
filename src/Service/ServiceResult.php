<?php
declare(strict_types=1);

namespace App\Service;

class ServiceResult
{
    /**
     * @param bool $success Whether the operation succeeded.
     * @param mixed $data Payload on success.
     * @param array<string> $errors Error messages on failure.
     */
    public function __construct(
        public readonly bool $success,
        public readonly mixed $data = null,
        public readonly array $errors = [],
    ) {
    }

    /**
     * Create a success result.
     *
     * @param mixed $data Payload.
     * @return self
     */
    public static function ok(mixed $data = null): self
    {
        return new self(success: true, data: $data);
    }

    /**
     * Create a failure result.
     *
     * @param array<string>|string $errors Error messages.
     * @return self
     */
    public static function fail(string|array $errors): self
    {
        $errors = is_string($errors) ? [$errors] : $errors;

        return new self(success: false, errors: $errors);
    }

    /**
     * Check if result has errors.
     *
     * @return bool
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Get first error message.
     *
     * @return string|null
     */
    public function firstError(): ?string
    {
        return $this->errors[0] ?? null;
    }
}
