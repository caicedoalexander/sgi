<?php
declare(strict_types=1);

namespace App\Service\Approval;

use App\Service\Approval\Store\SingleUseTokenStore;
use App\Service\Strategy\InvoiceApprovalStrategy;
use App\Service\Strategy\NoveltyApprovalStrategy;

/**
 * Coordinador de la aprobación externa genérica single-entity (tabla approval_tokens).
 *
 * Resuelve y consume un token single-use por entidad (factura o novedad) y delega
 * el efecto de dominio en la Strategy correspondiente. La emisión de tokens ya no
 * vive aquí: facturas usan InvoiceApprovalService (invoice_approvals, multi-aprobador)
 * y novedades usan NoveltyApprovalService; este servicio solo cubre el consumo del
 * enlace de aprobación recibido por el aprobador.
 */
final class ExternalApprovalService
{
    /**
     * @var array<string, \App\Service\Strategy\ApprovalStrategyInterface>
     */
    private array $strategies;

    private ApprovalTokenManager $manager;

    private ApprovalTokenStore $store;

    /**
     * @param \App\Service\Strategy\InvoiceApprovalStrategy $invoiceStrategy Strategy de facturas.
     * @param \App\Service\Strategy\NoveltyApprovalStrategy $noveltyStrategy Strategy de novedades.
     * @param \App\Service\Approval\ApprovalTokenManager|null $manager Núcleo del ciclo de vida del token.
     * @param \App\Service\Approval\ApprovalTokenStore|null $store Store de persistencia del token.
     */
    public function __construct(
        InvoiceApprovalStrategy $invoiceStrategy,
        NoveltyApprovalStrategy $noveltyStrategy,
        ?ApprovalTokenManager $manager = null,
        ?ApprovalTokenStore $store = null,
    ) {
        $this->strategies = [
            'invoices' => $invoiceStrategy,
            'employee_novelties' => $noveltyStrategy,
        ];
        $this->manager = $manager ?? new ApprovalTokenManager();
        $this->store = $store ?? new SingleUseTokenStore();
    }

    /**
     * Resuelve un token vigente a su registro, sin consumirlo.
     *
     * @param string $token El token a resolver.
     * @return object|null El registro vigente, o null si no existe/usado/expirado.
     */
    public function resolve(string $token): ?object
    {
        return $this->manager->resolve($this->store, $token);
    }

    /**
     * Consume un token single-use y aplica el efecto de dominio vía Strategy
     * DENTRO de la transacción de consumo: si la Strategy falla (o no existe para
     * el entity_type), el consumo se revierte y el token queda vivo (cierra S3).
     *
     * @param string $token El token a consumir.
     * @param string $action Acción tomada (approve|reject).
     * @param \App\Service\Approval\ConsumeContext $context Datos forenses del consumo.
     * @return bool True si se consumió y la Strategy aplicó la acción.
     */
    public function consume(string $token, string $action, ConsumeContext $context): bool
    {
        $consumed = $this->manager->consume(
            $this->store,
            $token,
            $action,
            $context,
            function (object $record) use ($action, $context): bool {
                $strategy = $this->strategies[$record->entity_type] ?? null;
                if ($strategy === null) {
                    return false;
                }

                return $strategy->apply(
                    $record->entity_id,
                    $action,
                    $context->observations,
                    $context->approvedByUserId,
                    $context->approvalDate,
                );
            },
        );

        return $consumed !== null;
    }

    /**
     * Resuelve la entidad de dominio asociada al token vía Strategy.
     *
     * @param string $entityType Tipo de entidad.
     * @param int $entityId ID de la entidad.
     * @return object|null La entidad, o null si el tipo es desconocido.
     */
    public function getEntity(string $entityType, int $entityId): ?object
    {
        $strategy = $this->strategies[$entityType] ?? null;

        return $strategy ? $strategy->getEntity($entityId) : null;
    }
}
