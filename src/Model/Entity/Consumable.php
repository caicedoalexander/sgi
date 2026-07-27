<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class Consumable extends Entity
{
    protected array $_accessible = [
        'reference' => true,
        'description' => true,
        'current_stock' => false,
        'minimum_stock' => true,
        'maximum_stock' => true,
        'operation_center_id' => true,
        'unit' => true,
        'created' => false,
        'modified' => false,
    ];

    /**
     * Predicate: consumible has current_stock at or below minimum_stock.
     */
    public function isLowStock(): bool
    {
        return (int)($this->current_stock ?? 0) <= (int)($this->minimum_stock ?? 0);
    }
}
