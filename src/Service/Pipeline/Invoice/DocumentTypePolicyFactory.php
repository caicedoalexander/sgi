<?php
declare(strict_types=1);

namespace App\Service\Pipeline\Invoice;

use App\Constants\InvoiceConstants;
use App\Service\Pipeline\Invoice\Policy\AnticipoDocumentTypePolicy;
use App\Service\Pipeline\Invoice\Policy\LegalizacionDocumentTypePolicy;
use App\Service\Pipeline\Invoice\Policy\ReciboCajaDocumentTypePolicy;
use App\Service\Pipeline\Invoice\Policy\StandardDocumentTypePolicy;

/**
 * Mapea document_type → DocumentTypePolicy concreta.
 * Siempre devuelve algo (cae a StandardDocumentTypePolicy); los consumidores
 * nunca verifican null.
 */
final class DocumentTypePolicyFactory
{
    /**
     * Clases policy por doctype especial (espejo de $byType). Fuente de las
     * consultas ESTÁTICAS de flags: los guards y states no necesitan construir
     * la factory (AnticipoDocumentTypePolicy requiere AdvanceLegalizationService).
     *
     * @var array<string, class-string<\App\Service\Pipeline\Invoice\DocumentTypePolicy>>
     */
    public const POLICY_CLASSES = [
        InvoiceConstants::DOCTYPE_ANTICIPO => AnticipoDocumentTypePolicy::class,
        InvoiceConstants::DOCTYPE_LEGALIZACION => LegalizacionDocumentTypePolicy::class,
        InvoiceConstants::DOCTYPE_RECIBO_CAJA => ReciboCajaDocumentTypePolicy::class,
    ];

    /**
     * @var array<string, \App\Service\Pipeline\Invoice\DocumentTypePolicy>
     */
    private array $byType;

    /**
     * @param \App\Service\Pipeline\Invoice\Policy\StandardDocumentTypePolicy $standard Policy por defecto (fallback).
     * @param \App\Service\Pipeline\Invoice\Policy\AnticipoDocumentTypePolicy $anticipo Policy del doctype Anticipo.
     * @param \App\Service\Pipeline\Invoice\Policy\LegalizacionDocumentTypePolicy $legalizacion Policy del doctype Legalización.
     * @param \App\Service\Pipeline\Invoice\Policy\ReciboCajaDocumentTypePolicy|null $reciboCaja Policy del doctype Recibo de Caja.
     */
    public function __construct(
        private readonly StandardDocumentTypePolicy $standard,
        AnticipoDocumentTypePolicy $anticipo,
        LegalizacionDocumentTypePolicy $legalizacion,
        ?ReciboCajaDocumentTypePolicy $reciboCaja = null,
    ) {
        $this->byType = [
            InvoiceConstants::DOCTYPE_ANTICIPO     => $anticipo,
            InvoiceConstants::DOCTYPE_LEGALIZACION => $legalizacion,
            InvoiceConstants::DOCTYPE_RECIBO_CAJA  => $reciboCaja ?? new ReciboCajaDocumentTypePolicy(),
        ];
    }

    /**
     * Resuelve el document_type a su DocumentTypePolicy concreta; cae a Standard.
     *
     * @param string|null $documentType Valor de InvoiceConstants::DOCTYPE_* o null.
     * @return \App\Service\Pipeline\Invoice\DocumentTypePolicy
     */
    public function for(?string $documentType): DocumentTypePolicy
    {
        return $this->byType[$documentType] ?? $this->standard;
    }

    /** ¿El doctype indicado exige dian_validation='Aprobada' para avanzar? Ver DocumentTypePolicy::requiresDianValidation(). */
    public static function requiresDianFor(?string $documentType): bool
    {
        $class = self::POLICY_CLASSES[$documentType] ?? StandardDocumentTypePolicy::class;

        return $class::requiresDianValidation();
    }

    /** ¿El doctype indicado exige ≥1 documento en invoice_documents para avanzar? Ver DocumentTypePolicy::requiresSupportDocument(). */
    public static function requiresSupportFor(?string $documentType): bool
    {
        $class = self::POLICY_CLASSES[$documentType] ?? StandardDocumentTypePolicy::class;

        return $class::requiresSupportDocument();
    }

    /** @return list<string> Doctypes exentos de DIAN (derivado de las policies, única fuente). */
    public static function dianExemptDocumentTypes(): array
    {
        return array_values(array_keys(array_filter(
            self::POLICY_CLASSES,
            static fn(string $class): bool => !$class::requiresDianValidation(),
        )));
    }

    /** @return list<string> Doctypes exentos de soporte. */
    public static function supportExemptDocumentTypes(): array
    {
        return array_values(array_keys(array_filter(
            self::POLICY_CLASSES,
            static fn(string $class): bool => !$class::requiresSupportDocument(),
        )));
    }
}
