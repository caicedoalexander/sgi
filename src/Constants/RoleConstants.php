<?php
declare(strict_types=1);

namespace App\Constants;

/**
 * Nombres de roles del sistema.
 *
 * Si se renombra un rol en la BD, actualizar aquí y todo el sistema
 * tomará el nuevo nombre automáticamente.
 */
final class RoleConstants
{
    public const ADMIN = 'Administrador';
    public const REGISTRO_REVISION = 'Registro/Revisión';
    public const CONTABILIDAD = 'Contabilidad';
    public const TESORERIA = 'Tesorería';
    public const AUXILIAR_PERSONAL = 'Auxiliar de Personal';
    public const ASISTENTE_PERSONAL = 'Asistente de Personal';
    public const CONTADOR = 'Contador';
    public const COORDINADOR_ADMIN = 'Coordinador Administrativo y Financiero';
}
