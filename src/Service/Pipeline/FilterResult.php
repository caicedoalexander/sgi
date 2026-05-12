<?php
declare(strict_types=1);

namespace App\Service\Pipeline;

/**
 * Resultado de `PipelineFieldPolicy::filterEntityData()`: contiene los campos
 * editables ya filtrados (`patch`) y los errores de validación inline detectados
 * (`errors`). Es inmutable por diseño.
 */
final class FilterResult
{
    /**
     * @param array<string, mixed> $patch Campos a aplicar vía patchEntity().
     * @param array<int, string> $errors Mensajes de validación inline (bloquean save).
     */
    public function __construct(
        public readonly array $patch,
        public readonly array $errors,
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
