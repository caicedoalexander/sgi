<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AdvanceConstants;
use App\Constants\AssetConstants;
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
    /**
     * Generate the next petty cash record code for an operation center.
     *
     * @param int $operationCenterId Operation center id.
     * @return string
     */
    public function generatePettyCashCode(int $operationCenterId): string
    {
        return $this->generate(
            PettyCashConstants::CODE_PREFIX,
            'PettyCashRecords',
            'code',
            $operationCenterId,
        );
    }

    /**
     * Generate the next refund code for an operation center.
     *
     * @param int $operationCenterId Operation center id.
     * @return string
     */
    public function generateRefundCode(int $operationCenterId): string
    {
        return $this->generate(
            RefundConstants::CODE_PREFIX,
            'Refunds',
            'code',
            $operationCenterId,
        );
    }

    /**
     * Generate the next payment scheduling code for an operation center.
     *
     * @param int $operationCenterId Operation center id.
     * @return string
     */
    public function generatePaymentSchedulingCode(int $operationCenterId): string
    {
        return $this->generate(
            PaymentSchedulingConstants::CODE_PREFIX,
            'PaymentSchedulings',
            'code',
            $operationCenterId,
        );
    }

    /**
     * Generate the next advance invoice number for an operation center.
     *
     * @param int $operationCenterId Operation center id.
     * @return string
     */
    public function generateAdvanceInvoiceNumber(int $operationCenterId): string
    {
        return $this->generate(
            AdvanceConstants::CODE_PREFIX,
            'Invoices',
            'invoice_number',
            $operationCenterId,
        );
    }

    /** @param int $operationCenterId Centro de operación. @return string */
    public function generateAssetCode(int $operationCenterId): string
    {
        return $this->generate(
            AssetConstants::CODE_PREFIX,
            'Assets',
            'code',
            $operationCenterId,
        );
    }

    /**
     * Build the next {PREFIX}-{YY}-{CCC}-{NNNN} code by scanning the table's last
     * matching value for the year and operation center.
     *
     * @param string $prefix Module code prefix.
     * @param string $tableAlias CakePHP table alias holding the codes.
     * @param string $codeField Column storing the code.
     * @param int $operationCenterId Operation center id (supplies the center segment).
     * @return string
     */
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

    /**
     * Zero-pad a numeric operation-center code to three digits.
     *
     * @param string $code Raw operation center code (must be numeric).
     * @return string
     * @throws \RuntimeException When the code is not numeric.
     */
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
