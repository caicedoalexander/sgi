<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AdvanceConstants;
use App\Constants\PaymentSchedulingConstants;
use App\Constants\PettyCashConstants;
use App\Constants\RefundConstants;
use Cake\ORM\TableRegistry;
use RuntimeException;

/**
 * Genera códigos del formato {PREFIX}-{YY}-{CCC}-{NNNN}.
 * Consecutivo único por (módulo, año, centro de operación).
 */
final class CodeGeneratorService
{
    public function generatePettyCashCode(int $operationCenterId): string
    {
        return $this->generate(
            PettyCashConstants::CODE_PREFIX,
            'PettyCashRecords',
            'code',
            $operationCenterId,
        );
    }

    public function generateRefundCode(int $operationCenterId): string
    {
        return $this->generate(
            RefundConstants::CODE_PREFIX,
            'Refunds',
            'code',
            $operationCenterId,
        );
    }

    public function generatePaymentSchedulingCode(int $operationCenterId): string
    {
        return $this->generate(
            PaymentSchedulingConstants::CODE_PREFIX,
            'PaymentSchedulings',
            'code',
            $operationCenterId,
        );
    }

    public function generateAdvanceInvoiceNumber(int $operationCenterId): string
    {
        return $this->generate(
            AdvanceConstants::CODE_PREFIX,
            'Invoices',
            'invoice_number',
            $operationCenterId,
        );
    }

    private function generate(string $prefix, string $tableAlias, string $codeField, int $operationCenterId): string
    {
        $center = TableRegistry::getTableLocator()
            ->get('OperationCenters')
            ->get($operationCenterId);

        $centerCode = $this->normalizeCenterCode((string)$center->code);
        $year = date('y');
        $base = sprintf('%s-%s-%s-', $prefix, $year, $centerCode);

        $table = TableRegistry::getTableLocator()->get($tableAlias);
        $last = $table->find()
            ->select([$codeField])
            ->where([$codeField . ' LIKE' => $base . '%'])
            ->orderBy([$codeField => 'DESC'])
            ->first();

        $next = 1;
        if ($last !== null && preg_match('/-(\d{4})$/', (string)$last->{$codeField}, $m)) {
            $next = (int)$m[1] + 1;
        }

        return $base . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    }

    private function normalizeCenterCode(string $code): string
    {
        if (!ctype_digit($code)) {
            throw new RuntimeException(
                'El código del centro de operación debe ser numérico, recibido: ' . $code,
            );
        }

        return str_pad($code, 3, '0', STR_PAD_LEFT);
    }
}
