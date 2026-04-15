<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\PaymentRegistryService;

class PaymentRegistryController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    private PaymentRegistryService $registryService;

    /**
     * @inheritDoc
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->registryService = new PaymentRegistryService();
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
