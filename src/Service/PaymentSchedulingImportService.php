<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use Cake\ORM\TableRegistry;
use PhpOffice\PhpSpreadsheet\IOFactory;

class PaymentSchedulingImportService
{
    public function __construct(
        private readonly InvoicePaymentService $paymentService,
    ) {
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
                if ($number === '') {
                    $number = ltrim($part, '0') ?: '0';
                }
            } else {
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

        $currentProvider = null;
        $currentBank = null;
        $currentBankCode = null;
        $currentNit = null;
        $headerSkipped = false;

        foreach ($rows as $rowNum => $row) {
            if (!$headerSkipped) {
                $headerSkipped = true;
                continue;
            }

            $colA = trim((string)($row['A'] ?? ''));
            $colB = trim((string)($row['B'] ?? ''));
            $colE = $row['E'] ?? null;

            if (empty($colA) && empty($colB) && ($colE === null || trim((string)$colE) === '')) {
                continue;
            }

            // --- TIPO 1: Encabezado de proveedor ---
            if ($colA !== '' && ctype_alpha($colA) && preg_match('/^\d+/', $colB)) {
                $currentBankCode = $colA;
                $currentNit = $this->_extractNit($colB);

                $currentProvider = $providersTable->find()
                    ->where(['document_number' => $currentNit])
                    ->first();

                if (!$currentProvider) {
                    $errors[] = "Fila {$rowNum}: Proveedor con NIT '{$currentNit}' no encontrado en SGI.";
                    $currentProvider = null;
                }

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
            if (!empty($colB) && $colE !== null && trim((string)$colE) !== '') {
                $amount = (float)$colE;

                if (!$currentProvider) {
                    $errors[] = "Fila {$rowNum}: Factura '{$colB}' sin proveedor válido asociado.";
                    continue;
                }
                if (!$currentBank) {
                    $errors[] = "Fila {$rowNum}: Factura '{$colB}' sin banco válido asociado.";
                    continue;
                }

                $invoiceNumber = $this->_normalizeSiesaInvoiceNumber($colB);

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
        }

        return ['valid' => $valid, 'errors' => $errors];
    }
}
