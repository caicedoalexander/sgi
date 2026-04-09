<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use Cake\ORM\TableRegistry;
use PhpOffice\PhpSpreadsheet\IOFactory;

class PaymentSchedulingService
{
    private InvoicePaymentService $paymentService;

    public function __construct(?InvoicePaymentService $paymentService = null)
    {
        $this->paymentService = $paymentService ?? new InvoicePaymentService();
    }

    /**
     * Parsea el Excel y valida cada fila.
     * Retorna ['valid' => [...], 'errors' => [...]]
     */
    public function parseExcel(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $providersTable = TableRegistry::getTableLocator()->get('Providers');
        $bankingTable = TableRegistry::getTableLocator()->get('BankingEntities');

        $valid = [];
        $errors = [];
        $headerSkipped = false;

        foreach ($rows as $rowNum => $row) {
            if (!$headerSkipped) {
                $headerSkipped = true;
                continue;
            }

            $invoiceNumber = trim((string)($row['A'] ?? ''));
            $nit = trim((string)($row['B'] ?? ''));
            $amount = (float)($row['C'] ?? 0);
            $bankName = trim((string)($row['D'] ?? ''));

            if (empty($invoiceNumber) && empty($nit)) {
                continue; // Fila vacía
            }

            // Validar proveedor
            $provider = $providersTable->find()
                ->where(['document_number' => $nit])
                ->first();

            if (!$provider) {
                $errors[] = "Fila {$rowNum}: Proveedor con NIT '{$nit}' no encontrado.";
                continue;
            }

            // Validar factura
            $invoice = $invoicesTable->find()
                ->where([
                    'invoice_number' => $invoiceNumber,
                    'provider_id' => $provider->id,
                ])
                ->first();

            if (!$invoice) {
                $errors[] = "Fila {$rowNum}: Factura '{$invoiceNumber}' del proveedor '{$nit}' no encontrada.";
                continue;
            }

            if ($invoice->pipeline_status !== InvoiceConstants::STATUS_TESORERIA) {
                $errors[] = "Fila {$rowNum}: Factura '{$invoiceNumber}' no está en estado Tesorería (estado actual: {$invoice->pipeline_status}).";
                continue;
            }

            // Validar banco
            $bank = $bankingTable->find()
                ->where([
                    'OR' => [
                        'name' => $bankName,
                        'code' => $bankName,
                    ],
                    'active' => true,
                ])
                ->first();

            if (!$bank) {
                $errors[] = "Fila {$rowNum}: Banco '{$bankName}' no encontrado.";
                continue;
            }

            // Validar monto
            if ($amount <= 0) {
                $errors[] = "Fila {$rowNum}: El monto debe ser positivo.";
                continue;
            }

            $pendingBalance = $this->paymentService->getPendingBalance($invoice->id);
            if ($amount > $pendingBalance) {
                $errors[] = "Fila {$rowNum}: El monto (\${$amount}) excede el saldo pendiente (\${$pendingBalance}) de la factura '{$invoiceNumber}'.";
                continue;
            }

            $valid[] = [
                'row' => $rowNum,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoiceNumber,
                'provider_name' => $provider->name,
                'banking_entity_id' => $bank->id,
                'bank_name' => $bank->name,
                'amount' => $amount,
            ];
        }

        return ['valid' => $valid, 'errors' => $errors];
    }

    /**
     * Vincula items validados a una programación.
     */
    public function linkItems(int $schedulingId, array $validItems): bool
    {
        $itemsTable = TableRegistry::getTableLocator()->get('PaymentSchedulingItems');

        foreach ($validItems as $item) {
            $entity = $itemsTable->newEntity([
                'payment_scheduling_id' => $schedulingId,
                'invoice_id' => $item['invoice_id'],
                'banking_entity_id' => $item['banking_entity_id'],
                'amount' => $item['amount'],
            ]);

            if (!$itemsTable->save($entity)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Aplica los pagos de una programación autorizada.
     * Crea invoice_payments, recalcula payment_status, avanza facturas.
     */
    public function applyPayments(int $schedulingId, int $authorizedBy): array
    {
        $itemsTable = TableRegistry::getTableLocator()->get('PaymentSchedulingItems');
        $paymentsTable = TableRegistry::getTableLocator()->get('InvoicePayments');
        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $schedulingsTable = TableRegistry::getTableLocator()->get('PaymentSchedulings');

        $scheduling = $schedulingsTable->get($schedulingId);
        $items = $itemsTable->find()
            ->where(['payment_scheduling_id' => $schedulingId])
            ->all();

        $appliedInvoiceIds = [];
        $errors = [];

        foreach ($items as $item) {
            $payment = $paymentsTable->newEntity([
                'invoice_id' => $item->invoice_id,
                'banking_entity_id' => $item->banking_entity_id,
                'amount' => $item->amount,
                'payment_date' => date('Y-m-d'),
                'payment_scheduling_id' => $schedulingId,
                'authorized' => true,
                'authorized_by' => $authorizedBy,
                'authorized_date' => date('Y-m-d'),
                'created_by' => $scheduling->created_by,
            ]);

            if (!$paymentsTable->save($payment)) {
                $errors[] = "No se pudo crear pago para factura ID {$item->invoice_id}";
                continue;
            }

            $appliedInvoiceIds[] = $item->invoice_id;
        }

        // Recalcular payment_status y avanzar facturas
        $advanced = [];
        $partial = [];
        foreach (array_unique($appliedInvoiceIds) as $invoiceId) {
            $this->paymentService->recalculatePaymentStatus($invoiceId);

            $invoice = $invoicesTable->get($invoiceId);
            if ($invoice->payment_status === InvoiceConstants::PAYMENT_FULL) {
                $invoice->pipeline_status = InvoiceConstants::STATUS_PAGADA;
                $invoicesTable->save($invoice);
                $advanced[] = $invoiceId;
            } else {
                $partial[] = $invoiceId;
            }
        }

        return [
            'success' => empty($errors),
            'errors' => $errors,
            'advanced_to_pagada' => $advanced,
            'partial_payment' => $partial,
        ];
    }

    /**
     * Calcula el monto total de una programación.
     */
    public function calculateTotal(int $schedulingId): float
    {
        $itemsTable = TableRegistry::getTableLocator()->get('PaymentSchedulingItems');

        return (float)$itemsTable->find()
            ->where(['payment_scheduling_id' => $schedulingId])
            ->sumOf('amount');
    }
}
