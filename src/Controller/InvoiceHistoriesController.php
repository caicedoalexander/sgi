<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;

class InvoiceHistoriesController extends AppController
{
    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    #[Permission(action: 'view')]
    public function index()
    {
        $query = $this->InvoiceHistories->find()
            ->contain(['Invoices', 'Users'])
            ->orderBy(['InvoiceHistories.created' => 'DESC']);
        $invoiceHistories = $this->paginate($query);

        $this->set(compact('invoiceHistories'));
    }

    #[Permission(action: 'view')]
    public function view($id = null)
    {
        $invoiceHistory = $this->InvoiceHistories->get($id, contain: ['Invoices', 'Users']);

        $this->set(compact('invoiceHistory'));
    }
}
