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
     * Transforma número de factura de formato Siesa al formato SGI.
     * Ej: "FVE-00080933-00" → "FVE80933", "-00006755-00" → "6755"
     */
    private function _normalizeSiesaInvoiceNumber(string $raw): string
    {
        $parts = explode('-', $raw);

        $letters = '';
        $number = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (ctype_alpha($part)) {
                $letters .= $part;
            } elseif (ctype_digit($part)) {
                // Tomar el primer grupo numérico significativo (ignorar sufijo "00")
                if ($number === '') {
                    $number = ltrim($part, '0') ?: '0';
                }
            } else {
                // Parte mixta: separar letras y dígitos
                $letterPart = preg_replace('/[^A-Za-z]/', '', $part);
                $digitPart = preg_replace('/[^0-9]/', '', $part);
                if ($letterPart !== '') {
                    $letters .= $letterPart;
                }
                if ($digitPart !== '' && $number === '') {
                    $number = ltrim($digitPart, '0') ?: '0';
                }
            }
        }

        return $letters . $number;
    }

    /**
     * Extrae el NIT puro del formato Siesa (sin sufijo de sucursal).
     * Ej: "900474383-001" → "900474383"
     */
    private function _extractNit(string $raw): string
    {
        $parts = explode('-', $raw);

        return trim($parts[0]);
    }

    /**
     * Parsea el Excel de preprogramación de pagos (5 columnas).
     * Formato multi-fila: encabezado proveedor → factura(s).
     * Columnas: Banco aprovador (A), Proveedor/NIT (B), Razón Social (C), Saldo (D), Programado (E).
     * Retorna ['valid' => [...], 'errors' => [...]]
     */
    public function parseExcel(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, false, true);

        $invoicesTable = TableRegistry::getTableLocator()->get('Invoices');
        $providersTable = TableRegistry::getTableLocator()->get('Providers');
        $bankingTable = TableRegistry::getTableLocator()->get('BankingEntities');

        $valid = [];
        $errors = [];

        // Estado del proveedor actual mientras recorremos filas
        $currentProvider = null;
        $currentBank = null;
        $currentBankCode = null;
        $currentNit = null;
        $headerSkipped = false;

        foreach ($rows as $rowNum => $row) {
            // Saltar encabezado
            if (!$headerSkipped) {
                $headerSkipped = true;
                continue;
            }

            $colA = trim((string)($row['A'] ?? ''));
            $colB = trim((string)($row['B'] ?? ''));
            $colE = $row['E'] ?? null;

            // Fila vacía
            if (empty($colA) && empty($colB) && ($colE === null || trim((string)$colE) === '')) {
                continue;
            }

            // --- TIPO 1: Encabezado de proveedor ---
            // Col A tiene una letra (código banco) y Col B tiene NIT
            if ($colA !== '' && ctype_alpha($colA) && preg_match('/^\d+/', $colB)) {
                $currentBankCode = $colA;
                $currentNit = $this->_extractNit($colB);

                // Buscar proveedor
                $currentProvider = $providersTable->find()
                    ->where(['document_number' => $currentNit])
                    ->first();

                if (!$currentProvider) {
                    $errors[] = "Fila {$rowNum}: Proveedor con NIT '{$currentNit}' no encontrado en SGI.";
                    $currentProvider = null;
                }

                // Buscar banco por código
                $currentBank = $bankingTable->find()
                    ->where(['code' => $currentBankCode, 'active' => true])
                    ->first();

                if (!$currentBank) {
                    $errors[] = "Fila {$rowNum}: Banco con código '{$currentBankCode}' no encontrado.";
                    $currentBank = null;
                }

                continue;
            }

            // --- TIPO 2: Fila de factura ---
            // Col B tiene número de factura Siesa, Col E tiene monto programado
            if (!empty($colB) && $colE !== null && trim((string)$colE) !== '') {
                $amount = (float)$colE;

                // Si no hay proveedor/banco válido del encabezado, saltar
                if (!$currentProvider) {
                    $errors[] = "Fila {$rowNum}: Factura '{$colB}' sin proveedor válido asociado.";
                    continue;
                }
                if (!$currentBank) {
                    $errors[] = "Fila {$rowNum}: Factura '{$colB}' sin banco válido asociado.";
                    continue;
                }

                // Transformar número de factura
                $invoiceNumber = $this->_normalizeSiesaInvoiceNumber($colB);

                // Buscar factura en SGI
                $invoice = $invoicesTable->find()
                    ->where([
                        'invoice_number' => $invoiceNumber,
                        'provider_id' => $currentProvider->id,
                    ])
                    ->first();

                if (!$invoice) {
                    $errors[] = "Fila {$rowNum}: Factura '{$invoiceNumber}' (Siesa: '{$colB}') del proveedor '{$currentProvider->name}' no encontrada.";
                    continue;
                }

                if ($invoice->pipeline_status !== InvoiceConstants::STATUS_TESORERIA) {
                    $errors[] = "Fila {$rowNum}: Factura '{$invoiceNumber}' no está en estado Tesorería (estado actual: {$invoice->pipeline_status}).";
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
                    'provider_name' => $currentProvider->name,
                    'banking_entity_id' => $currentBank->id,
                    'bank_name' => $currentBank->name,
                    'amount' => $amount,
                ];

                continue;
            }

            // --- Fila TOTAL u otras filas no relevantes ---
            // Se ignoran silenciosamente
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

        $connection = $paymentsTable->getConnection();

        return $connection->transactional(function () use (
            $items, $paymentsTable, $invoicesTable, $scheduling, $schedulingId, $authorizedBy
        ) {
            $appliedInvoiceIds = [];
            $errors = [];

            foreach ($items as $item) {
                $payment = $paymentsTable->newEntity([
                    'invoice_id' => $item->invoice_id,
                    'banking_entity_id' => $item->banking_entity_id,
                    'amount' => $item->amount,
                    'payment_date' => date('Y-m-d'),
                    'payment_scheduling_id' => $schedulingId,
                    'status' => InvoiceConstants::PAYMENT_RECORD_AUTHORIZED,
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

            if (!empty($errors)) {
                return ['success' => false, 'errors' => $errors, 'advanced_to_pagada' => [], 'partial_payment' => []];
            }

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
                'success' => true,
                'errors' => [],
                'advanced_to_pagada' => $advanced,
                'partial_payment' => $partial,
            ];
        });
    }

    /**
     * Calcula el monto total de una programación.
     */
    public function calculateTotal(int $schedulingId): float
    {
        $itemsTable = TableRegistry::getTableLocator()->get('PaymentSchedulingItems');

        return (float)$itemsTable->find()
            ->where(['payment_scheduling_id' => $schedulingId])
            ->all()
            ->sumOf('amount');
    }
}
