<?php
declare(strict_types=1);

namespace App\Service\Pipeline\PettyCash\Guard;

use App\Service\Dto\GroupReadinessReport;
use App\Service\GroupReadinessQuery;

/**
 * IO del AgrupacionState puro de caja menor. Solo soporte: las hijas de caja
 * menor saltan el paso Aprobación por diseño (el vínculo certifica la
 * aprobación), así que el DIAN no les aplica. NO final: PHPUnit mockea el guard.
 */
class PettyCashGuard
{
    /** Requisitos pendientes (soporte) de las facturas hijas del registro de caja menor. */
    public function childRequirements(int $recordId): GroupReadinessReport
    {
        return GroupReadinessQuery::report(
            ['petty_cash_record_id' => $recordId],
            includeDian: false,
        );
    }
}
