<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\AssetConstants;
use Cake\ORM\Entity;

class Asset extends Entity
{
    protected array $_accessible = [
        'code' => false,
        'serial_number' => true,
        'asset_category_id' => true,
        'brand' => true,
        'model' => true,
        'description' => true,
        'status' => false,
        'responsible_employee_id' => false,
        'operation_center_id' => true,
        'cost_center_id' => true,
        'acquisition_date' => true,
        'observations' => true,
        'created' => false,
        'modified' => false,
    ];

    /** @return bool */
    public function isDisponible(): bool
    {
        return ($this->status ?? '') === AssetConstants::STATUS_DISPONIBLE;
    }

    /** @return bool */
    public function isAsignado(): bool
    {
        return ($this->status ?? '') === AssetConstants::STATUS_ASIGNADO;
    }

    /** @return bool */
    public function isDadoDeBaja(): bool
    {
        return ($this->status ?? '') === AssetConstants::STATUS_DADO_DE_BAJA;
    }
}
