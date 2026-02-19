<?php
declare(strict_types=1);

namespace App\Controller;

class InvoiceHistoriesController extends AppController
{
    public function index()
    {
        $query = $this->InvoiceHistories->find()
            ->contain(['Invoices', 'Users'])
            ->orderBy(['InvoiceHistories.created' => 'DESC']);
        $invoiceHistories = $this->paginate($query);

        $this->set(compact('invoiceHistories'));
    }

    public function view($id = null)
    {
        $invoiceHistory = $this->InvoiceHistories->get($id, contain: ['Invoices', 'Users']);

        $this->set(compact('invoiceHistory'));
    }
}
