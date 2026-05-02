<?php
declare(strict_types=1);

namespace App\Event;

use RuntimeException;

/**
 * Lanzada por un subscriber cuando la operación de dominio interna devuelve
 * ServiceResult::fail. Atraviesa EventManager::dispatch() y bubbles hasta
 * Connection::transactional(...), que captura, hace rollback y re-lanza.
 *
 * Mantener el constructor estándar de RuntimeException: $message + previous opcional.
 */
final class ListenerFailedException extends RuntimeException
{
}
