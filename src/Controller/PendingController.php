<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\NoAuthGate;
use App\Service\MyPendingService;
use App\View\Presentation\PendingPresentation;

/**
 * Bandeja personal "Mis Pendientes": agrega los ítems de los 8 módulos de flujo
 * cuyo estado actual el rol del usuario puede operar. Vista derivada de permisos
 * ya existentes (cada fila viene filtrada por lo que el rol opera), de ahí
 * #[NoAuthGate] y la ausencia de entrada en $controllerModuleMap.
 */
class PendingController extends AppController
{
    private MyPendingService $pendingService;

    /**
     * Configura componentes y servicios del controlador.
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->pendingService = $this->getContainer()->get(MyPendingService::class);
    }

    /**
     * Lista la bandeja personal de pendientes del usuario autenticado.
     *
     * @return void
     */
    #[NoAuthGate(
        reason: 'Vista personal derivada de permisos ya existentes; cada fila ya está filtrada por lo que el rol opera',
    )]
    public function index(): void
    {
        $roleId = (int)$this->Authentication->getIdentity()->getOriginalData()->role_id;

        $moduleQuery = $this->request->getQuery('module');
        $module = in_array($moduleQuery, MyPendingService::MODULE_SLUGS, true) ? $moduleQuery : null;
        $search = $this->request->getQuery('q');
        $search = is_string($search) ? $search : null;
        $page = max(1, (int)$this->request->getQuery('page'));

        $result = $this->pendingService->getPending($roleId, $module, $search, $page);

        $this->set([
            'rows' => array_map(
                [PendingPresentation::class, 'forRow'],
                $result['items'],
            ),
            'total' => $result['total'],
            'page' => $result['page'],
            'perPage' => $result['perPage'],
            'activeModule' => $module,
            'search' => $search,
        ]);
    }
}
