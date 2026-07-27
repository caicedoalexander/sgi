<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class AssetDocument extends Entity
{
    protected array $_accessible = [
        'asset_id' => true,
        'asset_movement_id' => true,
        'document_type' => true,
        'name' => true,
        'file_path' => true,
        'file_size' => true,
        'mime_type' => true,
        'uploaded_by' => true,
        'created' => false,
        'modified' => false,
    ];
}
