<?php
declare(strict_types=1);

namespace App\Constants;

/**
 * Configuración canónica de uploads de archivos en el SPI.
 *
 * Fuente única para:
 * - Límite de tamaño en bytes (validación server-side en DocumentUploadTrait,
 *   EmployeeDocumentService y guard client-side vía meta tag en layout/default.php).
 * - Label humano del límite, mostrado en form-text de uploads y en mensajes de error.
 *
 * Si nginx o php.ini tienen un límite menor, ese gana — esta constante representa
 * el límite aplicacional, no el infraestructural. Mantener sincronizado con
 * client_max_body_size de nginx y upload_max_filesize/post_max_size de php.ini.
 */
final class UploadConstants
{
    public const MAX_BYTES = 20 * 1024 * 1024;
    public const MAX_BYTES_LABEL = '20 MB';
}
