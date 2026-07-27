<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Service\PaymentRegistryService;

class PaymentRegistryController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private PaymentRegistryService $registryService;

    /**
     * Configura componentes y servicios del controlador.
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->registryService = $this->getContainer()->get(PaymentRegistryService::class);
    }

    /**
     * List all payments across modules with filters and manual pagination.
     *
     * @return void
     */
    #[Permission(action: 'view')]
    public function index(): void
    {
        $filters = [
            'type' => $this->request->getQuery('type'),
            'authorized' => $this->request->getQuery('authorized'),
            'banking_entity_id' => $this->request->getQuery('banking_entity_id'),
            'date_from' => $this->request->getQuery('date_from'),
            'date_to' => $this->request->getQuery('date_to'),
        ];

        // Paginación en SQL: COUNT(*) por tabla para el total + ventana de página
        // acotada (cada sub-query trae a lo sumo offset+limit filas). Ya no se
        // materializa la tabla completa en memoria (M3).
        $page = max(1, (int)($this->request->getQuery('page') ?? 1));
        $limit = 15;
        $total = $this->registryService->count($filters);
        $payments = $this->registryService->getPage($filters, ($page - 1) * $limit, $limit);
        $totalPages = (int)ceil($total / $limit);

        $bankingEntities = $this->fetchTable('BankingEntities')->find('list')->toArray();

        $this->set(compact('payments', 'filters', 'bankingEntities', 'page', 'limit', 'total', 'totalPages'));
    }
}
