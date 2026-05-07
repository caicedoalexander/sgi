<?php
declare(strict_types=1);

namespace App\Constants;

use App\Constants\Domain\Advance\PipelineStatus;

final class AdvanceConstants
{
    // Phase 2 pipeline statuses — fuente única en App\Constants\Domain\Advance\PipelineStatus.
    public const STATUS_VALIDACION = PipelineStatus::VALIDACION->value;
    public const STATUS_REVISION_FIRMAS = PipelineStatus::REVISION_FIRMAS->value;
    public const STATUS_CONTABILIDAD = PipelineStatus::CONTABILIDAD->value;
    public const STATUS_TESORERIA = PipelineStatus::TESORERIA->value;
    public const STATUS_AUTORIZACION_PAGO = PipelineStatus::AUTORIZACION_PAGO->value;
    public const STATUS_LEGALIZADA = PipelineStatus::LEGALIZADA->value;

    public const PIPELINE_STATUSES = [
        self::STATUS_VALIDACION,
        self::STATUS_REVISION_FIRMAS,
        self::STATUS_CONTABILIDAD,
        self::STATUS_TESORERIA,
        self::STATUS_AUTORIZACION_PAGO,
        self::STATUS_LEGALIZADA,
    ];

    public const STATUS_LABELS = [
        self::STATUS_VALIDACION        => 'Validación',
        self::STATUS_REVISION_FIRMAS   => 'Revisión y Firmas',
        self::STATUS_CONTABILIDAD      => 'Contabilidad',
        self::STATUS_TESORERIA         => 'Tesorería',
        self::STATUS_AUTORIZACION_PAGO => 'Autorización de pago',
        self::STATUS_LEGALIZADA        => 'Legalizada',
    ];

    // Case types resolved by Contabilidad
    public const CASE_EXACTO = 'exacto';
    public const CASE_FALTANTE = 'faltante';
    public const CASE_SOBRANTE = 'sobrante';
    public const CASE_TYPES = [self::CASE_EXACTO, self::CASE_FALTANTE, self::CASE_SOBRANTE];

    // Signature lifecycle (advance_legalization_signatures.signature_status).
    // Slugs en inglés por convención de estados técnicos internos (ver CLAUDE.md
    // "Slug language convention"). El pipeline visible al usuario usa español.
    public const SIGNATURE_PENDING = 'pending';
    public const SIGNATURE_SIGNED = 'signed';
    public const SIGNATURE_REJECTED = 'rejected';

    public const SIGNATURE_STATUSES = [
        self::SIGNATURE_PENDING,
        self::SIGNATURE_SIGNED,
        self::SIGNATURE_REJECTED,
    ];

    // Permission module slug (matches AuthorizationService::MODULES key)
    public const MODULE = 'advances';

    // Código de invoice_number autogenerado para facturas-anticipo
    public const CODE_PREFIX = 'ANT';
}
