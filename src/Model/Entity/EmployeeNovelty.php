<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\NoveltyConstants;
use Cake\ORM\Entity;

class EmployeeNovelty extends Entity
{
    protected array $_accessible = [
        'employee_id' => true,
        'novelty_type_id' => true,
        'filing_date' => true,
        'permission_date' => true,
        'schedule_type' => true,
        'start_date' => true,
        'end_date' => true,
        'start_time' => true,
        'end_time' => true,
        'is_paid' => true,
        'reason' => true,
        'status' => true,
        'approved_by' => true,
        'approved_at' => true,
        'registered_by' => true,
        'employee_signature' => true,
        'coordinator_signature' => true,
        'observations' => true,
    ];

    /**
     * Check if novelty is pending.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === NoveltyConstants::STATUS_PENDING;
    }

    /**
     * Check if novelty is approved.
     *
     * @return bool
     */
    public function isApproved(): bool
    {
        return $this->status === NoveltyConstants::STATUS_APPROVED;
    }

    /**
     * Check if novelty is rejected.
     *
     * @return bool
     */
    public function isRejected(): bool
    {
        return $this->status === NoveltyConstants::STATUS_REJECTED;
    }
}
