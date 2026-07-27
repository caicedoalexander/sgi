<?php
declare(strict_types=1);

namespace App\View\Presentation;

/**
 * Fuente ÚNICA del color de cada tipo de estado de pipeline, compartida por
 * TODOS los módulos de flujo (Invoice, PettyCash, Refund, PaymentScheduling,
 * Advance, Novelty).
 *
 * Regla de dominio (decisión de producto): un mismo tipo de estado tiene el
 * mismo color en todos los módulos — `contabilidad` es naranja en factura,
 * caja menor, reintegro, etc. Cada `{Modulo}Presentation::STATUS_BADGES`
 * deriva sus pills de aquí y un test guard verifica que no haya drift.
 *
 * La clave es el slug persistido del estado (español, sin acentos), que es
 * idéntico entre módulos para el mismo concepto. Este es el único lugar donde
 * el slug se asocia a un color: análogo a la fuente única de los enums
 * `Domain/{Modulo}/PipelineStatus`.
 *
 * - `pill`    → clase `.pill-*-soft` del badge de estado.
 * - `variant` → clase de la barra `.pipeline-mini` (cadena vacía = verde base).
 */
final class PipelineColorMap
{
    public const DEFAULT_PILL = 'pill-muted';
    public const DEFAULT_VARIANT = '';

    /** slug de estado → ['pill' => clase pill, 'variant' => variante de barra]. */
    private const MAP = [
        // Estados iniciales / neutros (sin trabajo realizado).
        'registro'          => ['pill' => 'pill-muted',        'variant' => ''],
        'borrador'          => ['pill' => 'pill-muted',        'variant' => ''],
        'gdp'               => ['pill' => 'pill-muted',        'variant' => ''],

        // Revisión / aprobación (amarillo = pendiente de decisión).
        'aprobacion'        => ['pill' => 'pill-warning-soft', 'variant' => 'is-warning'],

        // Etapas operativas en curso (azul = en proceso).
        'agrupacion'        => ['pill' => 'pill-info-soft',    'variant' => 'is-info'],
        'validacion'        => ['pill' => 'pill-info-soft',    'variant' => 'is-info'],
        'tesoreria'         => ['pill' => 'pill-info-soft',    'variant' => 'is-info'],
        'revision_firmas'   => ['pill' => 'pill-info-soft',    'variant' => 'is-info'],

        // Contabilidad (naranja, distinguible de aprobación y tesorería contiguas).
        'contabilidad'      => ['pill' => 'pill-orange-soft',  'variant' => 'is-orange'],

        // Recursos humanos (café, propio de novedades).
        'rrhh'              => ['pill' => 'pill-accent-soft',  'variant' => 'is-accent'],

        // Autorización (amarillo = pendiente) / verificación (café = control final).
        'autorizacion_pago' => ['pill' => 'pill-warning-soft', 'variant' => 'is-warning'],
        'verificacion_pago' => ['pill' => 'pill-accent-soft',  'variant' => 'is-accent'],

        // Terminales de éxito (verde).
        'pagada'            => ['pill' => 'pill-primary-soft', 'variant' => ''],
        'legalizada'        => ['pill' => 'pill-primary-soft', 'variant' => ''],

        // Rechazo (rojo).
        'rechazada'         => ['pill' => 'pill-danger-soft',  'variant' => 'is-danger'],
    ];

    /** Clase pill del badge para un estado. */
    public static function pill(string $status): string
    {
        return self::MAP[$status]['pill'] ?? self::DEFAULT_PILL;
    }

    /** Variante de la barra `.pipeline-mini` para un estado. */
    public static function variant(string $status): string
    {
        return self::MAP[$status]['variant'] ?? self::DEFAULT_VARIANT;
    }

    /**
     * Construye el mapa estado→pill para los estados dados, en su mismo orden.
     * Pensado para alimentar/verificar los `STATUS_BADGES` de cada Presentation.
     *
     * @param list<string> $statuses
     * @return array<string, string>
     */
    public static function badgesFor(array $statuses): array
    {
        $out = [];
        foreach ($statuses as $status) {
            $out[$status] = self::pill($status);
        }

        return $out;
    }
}
