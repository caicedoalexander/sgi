<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\AdvanceConstants;
use Cake\ORM\Entity;

class AdvanceLegalizationSignature extends Entity
{
    protected array $_accessible = [
        'legalization_id' => true,
        'signed_by_user_id' => true,
        'signed_at' => true,
        'file_path' => true,
        'file_name' => true,
        'file_size' => true,
        'mime_type' => true,
        'signature_status' => true,
        'rejection_reason' => true,
    ];

    /**
     * @return bool true cuando la firma está pendiente de aplicarse
     */
    public function isPending(): bool
    {
        return $this->signature_status === AdvanceConstants::SIGNATURE_PENDING;
    }

    /**
     * @return bool true cuando la firma ya fue aplicada
     */
    public function isSigned(): bool
    {
        return $this->signature_status === AdvanceConstants::SIGNATURE_SIGNED;
    }

    /**
     * @return bool true cuando la firma fue rechazada
     */
    public function isRejected(): bool
    {
        return $this->signature_status === AdvanceConstants::SIGNATURE_REJECTED;
    }
}
