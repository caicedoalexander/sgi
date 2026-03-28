<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class NoveltyDocument extends Entity
{
    protected array $_accessible = [
        'novelty_id' => true,
        'liquidation_doc_id' => true,
        'document_type' => true,
        'pipeline_status' => true,
        'file_path' => true,
        'file_name' => true,
        'file_size' => true,
        'mime_type' => true,
        'uploaded_by' => true,
    ];
}
