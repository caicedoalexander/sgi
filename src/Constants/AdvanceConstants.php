<?php
declare(strict_types=1);

namespace App\Constants;

final class AdvanceConstants
{
    // Phase 2 pipeline statuses (advance_legalizations.status)
    public const STATUS_VALIDACION = 'validacion';
    public const STATUS_REVISION_FIRMAS = 'revision_firmas';
    public const STATUS_CONTABILIDAD = 'contabilidad';
    public const STATUS_TESORERIA = 'tesoreria';
    public const STATUS_LEGALIZADA = 'legalizada';

    public const PIPELINE_STATUSES = [
        self::STATUS_VALIDACION,
        self::STATUS_REVISION_FIRMAS,
        self::STATUS_CONTABILIDAD,
        self::STATUS_TESORERIA,
        self::STATUS_LEGALIZADA,
    ];

    public const STATUS_LABELS = [
        self::STATUS_VALIDACION => 'Validación',
        self::STATUS_REVISION_FIRMAS => 'Revisión y Firmas',
        self::STATUS_CONTABILIDAD => 'Contabilidad',
        self::STATUS_TESORERIA => 'Tesorería',
        self::STATUS_LEGALIZADA => 'Legalizada',
    ];

    public const STATUS_ICONS = [
        self::STATUS_VALIDACION => 'bi-clipboard-check',
        self::STATUS_REVISION_FIRMAS => 'bi-pen',
        self::STATUS_CONTABILIDAD => 'bi-calculator',
        self::STATUS_TESORERIA => 'bi-bank',
        self::STATUS_LEGALIZADA => 'bi-cash-coin',
    ];

    // Case types resolved by Contabilidad
    public const CASE_EXACTO = 'exacto';
    public const CASE_FALTANTE = 'faltante';
    public const CASE_SOBRANTE = 'sobrante';
    public const CASE_TYPES = [self::CASE_EXACTO, self::CASE_FALTANTE, self::CASE_SOBRANTE];

    // Signature lifecycle (advance_legalization_signatures.signature_status)
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
}
