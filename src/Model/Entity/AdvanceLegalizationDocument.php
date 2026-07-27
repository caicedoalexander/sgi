<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class AdvanceLegalizationDocument extends Entity
{
    protected array $_accessible = [
        'legalization_id' => true,
        'document_type' => true,
        'file_path' => true,
        'file_name' => true,
        'file_size' => true,
        'mime_type' => true,
        'uploaded_by' => true,
        'created' => true,
        'modified' => true,
        'advance_legalization' => true,
        'uploaded_by_user' => true,
    ];
}
