<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\AuthorizationService;
use App\Service\PaymentRegistryService;
use App\Service\SidebarCounterService;
use Cake\Controller\ComponentRegistry;
use Cake\Event\EventManagerInterface;
use Cake\Http\Response;
use Cake\Http\ServerRequest;

class PaymentRegistryController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    /**
     * @param \App\Service\PaymentRegistryService $registryService Registry service.
     * @param \App\Service\AuthorizationService $authService Authorization service.
     * @param \App\Service\SidebarCounterService $counterService Sidebar counters.
     * @param \Cake\Http\ServerRequest|null $request Request.
     * @param \Cake\Http\Response|null $response Response.
     * @param string|null $name Controller name.
     * @param \Cake\Event\EventManagerInterface|null $eventManager Event manager.
     * @param \Cake\Controller\ComponentRegistry|null $components Component registry.
     */
    public function __construct(
        private readonly PaymentRegistryService $registryService,
        AuthorizationService $authService,
        SidebarCounterService $counterService,
        ?ServerRequest $request = null,
        ?Response $response = null,
        ?string $name = null,
        ?EventManagerInterface $eventManager = null,
        ?ComponentRegistry $components = null,
    ) {
        parent::__construct($authService, $counterService, $request, $response, $name, $eventManager, $components);
    }

    /**
     * List all payments across modules with filters and manual pagination.
     *
     * @return void
     */
    public function index(): void
    {
        $filters = [
            'type' => $this->request->getQuery('type'),
            'authorized' => $this->request->getQuery('authorized'),
            'banking_entity_id' => $this->request->getQuery('banking_entity_id'),
            'date_from' => $this->request->getQuery('date_from'),
            'date_to' => $this->request->getQuery('date_to'),
        ];

        $allPayments = $this->registryService->getAll($filters);

        // Manual pagination
        $page = (int)($this->request->getQuery('page') ?? 1);
        $limit = 15;
        $total = count($allPayments);
        $payments = array_slice($allPayments, ($page - 1) * $limit, $limit);
        $totalPages = (int)ceil($total / $limit);

        $bankingEntities = $this->fetchTable('BankingEntities')->find('list')->toArray();

        $this->set(compact('payments', 'filters', 'bankingEntities', 'page', 'limit', 'total', 'totalPages'));
    }
}
