<?php
declare(strict_types=1);

namespace App\Service\Pipeline;

use App\Constants\InvoiceConstants;
use App\Service\Pipeline\Policy\AnticipoDocumentTypePolicy;
use App\Service\Pipeline\Policy\LegalizacionDocumentTypePolicy;
use App\Service\Pipeline\Policy\StandardDocumentTypePolicy;

/**
 * Mapea document_type → DocumentTypePolicy concreta.
 * Siempre devuelve algo (cae a StandardDocumentTypePolicy); los consumidores
 * nunca verifican null.
 */
final class DocumentTypePolicyFactory
{
    /** @var array<string, DocumentTypePolicy> */
    private array $byType;

    public function __construct(
        private readonly StandardDocumentTypePolicy $standard,
        AnticipoDocumentTypePolicy $anticipo,
        LegalizacionDocumentTypePolicy $legalizacion,
    ) {
        $this->byType = [
            InvoiceConstants::DOCTYPE_ANTICIPO     => $anticipo,
            InvoiceConstants::DOCTYPE_LEGALIZACION => $legalizacion,
        ];
    }

    public function for(?string $documentType): DocumentTypePolicy
    {
        return $this->byType[$documentType] ?? $this->standard;
    }
}
