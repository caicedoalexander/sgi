<?php
declare(strict_types=1);

namespace App\Model\ValueObject;

use InvalidArgumentException;

/**
 * Value Object inmutable que representa la identificación de un empleado
 * (tipo de documento + número). Construido desde campos planos del entity
 * vía el getter virtual Employee::_getIdentification (CR-025).
 */
final readonly class Identification
{
    /**
     * @param string $type Tipo de documento (CC, CE, etc.).
     * @param string $number Número de documento.
     */
    public function __construct(
        public string $type,
        public string $number,
    ) {
        if ($type === '') {
            throw new InvalidArgumentException('document_type no puede estar vacío.');
        }
        if ($number === '') {
            throw new InvalidArgumentException('document_number no puede estar vacío.');
        }
    }

    /**
     * Formato canónico para mostrar en UI: "{TIPO} · {NUMERO}".
     * Mantiene el separador histórico usado en templates/Employees/view.php.
     */
    public function formatted(): string
    {
        return $this->type . ' · ' . $this->number;
    }

    /**
     * @param self $other Otra identificación a comparar.
     */
    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->number === $other->number;
    }

    /**
     * Permite usar el VO como string en concatenaciones y echo.
     */
    public function __toString(): string
    {
        return $this->formatted();
    }
}
