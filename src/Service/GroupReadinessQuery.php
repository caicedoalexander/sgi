<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\InvoiceConstants;
use App\Service\Dto\GroupReadinessReport;
use App\Service\Pipeline\Invoice\DocumentTypePolicyFactory;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

/**
 * Queries compartidas de los guards de grupo (Refund/PettyCash/Advance).
 * Las exenciones por doctype salen de DocumentTypePolicyFactory (única fuente);
 * con lista de exentos vacía se OMITE la cláusula NOT IN (CakePHP lanza
 * excepción con IN/NOT IN sobre array vacío).
 */
final class GroupReadinessQuery
{
    /**
     * @param array<string, mixed> $conditions Condiciones que seleccionan las hijas (p.ej. ['refund_id' => $id]).
     */
    public static function report(array $conditions, bool $includeDian = true): GroupReadinessReport
    {
        $dianPending = [];
        if ($includeDian) {
            $dianConditions = $conditions + ['dian_validation !=' => InvoiceConstants::DIAN_APPROVED];
            $dianExempt = DocumentTypePolicyFactory::dianExemptDocumentTypes();
            if ($dianExempt !== []) {
                $dianConditions['document_type NOT IN'] = $dianExempt;
            }
            $dianPending = self::_numbersById(self::_invoices()->find()->where($dianConditions));
        }

        $supportConditions = $conditions;
        $supportExempt = DocumentTypePolicyFactory::supportExemptDocumentTypes();
        if ($supportExempt !== []) {
            $supportConditions['document_type NOT IN'] = $supportExempt;
        }
        $supportQuery = self::_invoices()->find()
            ->where($supportConditions)
            ->where(function ($exp) {
                $docs = TableRegistry::getTableLocator()->get('InvoiceDocuments');
                $sub = $docs->find()
                    ->select(['InvoiceDocuments.id'])
                    ->where(['InvoiceDocuments.invoice_id = Invoices.id']);

                return $exp->notExists($sub);
            });
        $supportMissing = self::_numbersById($supportQuery);

        return new GroupReadinessReport($dianPending, $supportMissing);
    }

    /** Tabla Invoices vía locator (convención de servicios: nunca $this->TableName). */
    private static function _invoices(): Table
    {
        return TableRegistry::getTableLocator()->get('Invoices');
    }

    /** @return array<int, string> */
    private static function _numbersById(SelectQuery $query): array
    {
        $result = [];
        foreach ($query->select(['id', 'invoice_number'])->all() as $invoice) {
            $result[(int)$invoice->id] = $invoice->invoice_number ?: '#' . $invoice->id;
        }

        return $result;
    }
}
