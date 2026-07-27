<?php
declare(strict_types=1);

namespace App\View\Presentation;

/** DTO de fila para element/grouped_invoices_table (facturas hijas de un padre). */
final readonly class GroupedInvoiceRowView
{
    /**
     * @param int $id ID de la factura hija.
     * @param string $number Número de factura.
     * @param string $beneficiaryName Nombre del beneficiario (proveedor, empleado o titular de Recibo de Caja).
     * @param string $documentType Tipo de documento de la factura.
     * @param float $amount Monto de la factura.
     * @param string|null $issueDate Fecha de emisión formateada, o null.
     * @param string $statusLabel Etiqueta ES del estado.
     * @param string $statusPill Clase de pill del estado.
     * @param string $dianMode Modo de la celda DIAN: 'na' | 'select' | 'pill'.
     * @param string $dianValue Valor de validación DIAN.
     * @param string $dianPill Clase de pill de la validación DIAN.
     * @param bool $supportRequired El doctype exige soporte documental.
     * @param int $docsCount Número de documentos cargados.
     * @param bool $supportOk El soporte requerido está cubierto.
     * @param string $childStatus Estado de pipeline de la factura hija.
     */
    public function __construct(
        public int $id,
        public string $number,
        public string $beneficiaryName,
        public string $documentType,
        public float $amount,
        public ?string $issueDate,
        public string $statusLabel,
        public string $statusPill,
        public string $dianMode, // 'na' | 'select' | 'pill'
        public string $dianValue,
        public string $dianPill,
        public bool $supportRequired,
        public int $docsCount,
        public bool $supportOk,
        public string $childStatus,
    ) {
    }
}
