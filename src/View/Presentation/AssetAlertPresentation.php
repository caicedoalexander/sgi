<?php
declare(strict_types=1);

namespace App\View\Presentation;

use App\Constants\AssetAlertConstants;

final class AssetAlertPresentation
{
    /** @var array<string, string> */
    public const PRIORITY_BADGES = [
        AssetAlertConstants::PRIORITY_ALTA => 'pill-danger-soft',
        AssetAlertConstants::PRIORITY_MEDIA => 'pill-warning-soft',
        AssetAlertConstants::PRIORITY_BAJA => 'pill-secondary-soft',
    ];

    /** @var array<string, string> */
    public const STATUS_BADGES = [
        AssetAlertConstants::STATUS_ABIERTA => 'pill-warning-soft',
        AssetAlertConstants::STATUS_RESUELTA => 'pill-accent-soft',
        AssetAlertConstants::STATUS_VENCIDA => 'pill-danger-soft',
    ];
}
