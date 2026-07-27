<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\NoveltyConstants;
use App\Model\Entity\EmployeeNovelty;
use App\Service\Approval\ApprovalTokenManager;
use App\Service\Approval\ApprovalTokenStore;
use App\Service\Approval\ApprovalUrlBuilder;
use App\Service\Approval\Store\SingleUseTokenStore;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Exception;

/**
 * Aprobación externa de novedades por jefe: emite el token, construye el enlace
 * y notifica al aprobador. Extrae la lógica que estaba duplicada inline en
 * EmployeeNoveltiesController (add/resend).
 *
 * Usa el núcleo reutilizable (ApprovalTokenManager + ApprovalTokenStore)
 * directamente. Devuelve ServiceResult; la decisión de cómo presentar el
 * éxito/fallo (Flash) queda en el controller.
 */
class NoveltyApprovalService
{
    private const ENTITY_TYPE = 'employee_novelties';

    private ApprovalTokenManager $manager;

    private ApprovalTokenStore $store;

    private Table $usersTable;

    private Table $noveltiesTable;

    /**
     * @param \App\Service\NotificationService $notificationService Envío de correo.
     * @param \App\Service\Approval\ApprovalTokenManager|null $manager Núcleo del token.
     * @param \App\Service\Approval\ApprovalTokenStore|null $store Store del token.
     * @param \Cake\ORM\Table|null $usersTable Tabla Users (inyectable para tests).
     * @param \Cake\ORM\Table|null $noveltiesTable Tabla EmployeeNovelties (inyectable para tests).
     */
    public function __construct(
        private readonly NotificationService $notificationService,
        ?ApprovalTokenManager $manager = null,
        ?ApprovalTokenStore $store = null,
        ?Table $usersTable = null,
        ?Table $noveltiesTable = null,
    ) {
        $this->manager = $manager ?? new ApprovalTokenManager();
        $this->store = $store ?? new SingleUseTokenStore();
        $this->usersTable = $usersTable ?? TableRegistry::getTableLocator()->get('Users');
        $this->noveltiesTable = $noveltiesTable ?? TableRegistry::getTableLocator()->get('EmployeeNovelties');
    }

    /**
     * Emite un token de aprobación para la novedad y envía el correo al aprobador.
     *
     * @param \App\Model\Entity\EmployeeNovelty $novelty Novedad a aprobar.
     * @param int $createdByUserId Usuario que dispara la solicitud.
     * @param string $baseUrl URL base para el enlace de aprobación.
     * @return \App\Service\ServiceResult ok: data = ['approvalUrl' => string]; fail: motivo.
     */
    public function sendApprovalLink(EmployeeNovelty $novelty, int $createdByUserId, string $baseUrl): ServiceResult
    {
        if (empty($novelty->approver_id)) {
            return ServiceResult::fail(['La novedad no tiene un aprobador asignado.']);
        }

        $token = $this->manager->issue($this->store, self::ENTITY_TYPE, (int)$novelty->id, $createdByUserId);
        $approvalUrl = ApprovalUrlBuilder::approveUrl($baseUrl, $token);

        $approver = $this->usersTable->get($novelty->approver_id);
        if (empty($approver->email)) {
            return ServiceResult::fail(['El aprobador asignado no tiene correo electrónico configurado.']);
        }

        $noveltyForEmail = $this->noveltiesTable->get((int)$novelty->id, contain: ['Employees', 'NoveltyTypes']);

        try {
            $this->notificationService->sendNoveltyApprovalEmail(
                $approver,
                $noveltyForEmail,
                $approvalUrl,
                $createdByUserId,
            );
        } catch (Exception $e) {
            return ServiceResult::fail([$e->getMessage()]);
        }

        return ServiceResult::ok(['approvalUrl' => $approvalUrl]);
    }

    /**
     * Emite un token de aprobación para la novedad (sin enviar correo) y devuelve
     * el secreto en claro, o null si la novedad no es del aprobador o ya no está
     * pendiente. Usado por la bandeja autenticada para llevar al aprobador al
     * panel /approve.
     */
    public function issueApprovalToken(int $noveltyId, int $userId): ?string
    {
        $novelty = $this->noveltiesTable->find()
            ->where(['id' => $noveltyId, 'approver_id' => $userId])
            ->first();

        if ($novelty === null) {
            return null;
        }

        $area = $novelty->area_approval;
        if ($area !== null && $area !== NoveltyConstants::APPROVAL_PENDING) {
            return null;
        }

        return $this->manager->issue($this->store, self::ENTITY_TYPE, $noveltyId, $userId);
    }
}
