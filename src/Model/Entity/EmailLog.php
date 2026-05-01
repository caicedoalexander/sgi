<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * @property int $id
 * @property string $event_type
 * @property string|null $entity_type
 * @property int|null $entity_id
 * @property string $to_email
 * @property string $subject
 * @property string $template
 * @property array $payload
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 * @property \Cake\I18n\DateTime|null $last_attempt_at
 * @property \Cake\I18n\DateTime|null $sent_at
 * @property int|null $created_by
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 */
class EmailLog extends Entity
{
    protected array $_accessible = [
        'event_type' => true,
        'entity_type' => true,
        'entity_id' => true,
        'to_email' => true,
        'subject' => true,
        'template' => true,
        'payload' => true,
        'status' => true,
        'attempts' => true,
        'last_error' => true,
        'last_attempt_at' => true,
        'sent_at' => true,
        'created_by' => true,
    ];
}
