<?php
declare(strict_types=1);

namespace App\ViewModel;

use App\Model\Entity\Refund;

/**
 * Datos pre-calculados que el template `templates/Refunds/edit.php` necesita.
 * Construido por `RefundsController::_buildEditViewModel()` y pasado como `$viewModel`.
 */
final readonly class RefundEditViewModel
{
    /**
     * @param \App\Model\Entity\Refund $record Refund cargado con todos sus contains.
     * @param string $currentStatus Estado actual del refund.
     * @param array<int, string> $employees Lista [id => nombre completo].
     * @param array<int, string> $providers Lista [id => name].
     * @param iterable $operationCenters Centros de operación (find('codeList')).
     * @param array<int, string> $bankingEntities Lista [id => name].
     * @param iterable $availableInvoices Facturas elegibles para agrupar.
     * @param array $groupFilters Filtros aplicados al listado de facturas disponibles.
     * @param string|null $nextStatus Próximo estado del pipeline si aplica.
     * @param array<string> $advanceErrors Errores que impiden avanzar (calculados con el State).
     * @param bool $canRegress True si el rol puede regresar el registro.
     * @param string|null $previousStatus Estado anterior si la regresión está disponible.
     * @param string|null $regressLockMessage Mensaje de bloqueo de regresión, null si está permitida.
     * @param bool $canRegisterPayment True si el rol puede registrar pagos en este registro.
     * @param bool $canAuthorizePayment True si el rol puede autorizar pagos.
     * @param array<int, \App\Service\Dto\BulkPaymentView> $syntheticPayments Vista del pago bulk (0 o 1 items).
     * @param string $roleName Nombre del rol del usuario actual.
     * @param array<string, string> $pipelineLabels Mapa estado => label.
     */
    public function __construct(
        public Refund $record,
        public string $currentStatus,
        public array $employees,
        public array $providers,
        public iterable $operationCenters,
        public array $bankingEntities,
        public iterable $availableInvoices,
        public array $groupFilters,
        public ?string $nextStatus,
        public array $advanceErrors,
        public bool $canRegress,
        public ?string $previousStatus,
        public ?string $regressLockMessage,
        public bool $canRegisterPayment,
        public bool $canAuthorizePayment,
        public bool $canConfirmPayment,
        public array $syntheticPayments,
        public string $roleName,
        public array $pipelineLabels,
    ) {
    }
}
