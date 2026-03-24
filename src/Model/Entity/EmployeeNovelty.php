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
        'pipeline_status' => true,
        'approved_by' => true,
        'approved_at' => true,
        'registered_by' => true,
        'employee_signature' => true,
        'approver_id' => true,
        'area_approval' => true,
        'observations' => true,
        'passes_payroll' => true,
        'rrhh_by' => true,
        'liquidation_doc_id' => true,
        'custom_name' => true,
    ];

    public function isRejected(): bool
    {
        return $this->pipeline_status === NoveltyConstants::STATUS_RECHAZADA;
    }

    public function isPaid(): bool
    {
        return $this->pipeline_status === NoveltyConstants::STATUS_PAGADA;
    }

    public function isGrouped(): bool
    {
        return $this->liquidation_doc_id !== null;
    }

    public function isApprovalRejected(): bool
    {
        return $this->area_approval === NoveltyConstants::APPROVAL_REJECTED;
    }
}
