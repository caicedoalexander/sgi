<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

class NoveltyObservationService
{
    public function addToNovelty(int $noveltyId, int $userId, string $message): object|string
    {
        $table = TableRegistry::getTableLocator()->get('NoveltyObservations');
        $observation = $table->newEntity([
            'novelty_id' => $noveltyId,
            'user_id' => $userId,
            'message' => $message,
        ]);

        if (!$table->save($observation)) {
            return 'No se pudo guardar la observación.';
        }

        return $observation;
    }

    public function addToGroup(int $liquidationDocId, int $userId, string $message): object|string
    {
        $table = TableRegistry::getTableLocator()->get('NoveltyObservations');
        $observation = $table->newEntity([
            'liquidation_doc_id' => $liquidationDocId,
            'user_id' => $userId,
            'message' => $message,
        ]);

        if (!$table->save($observation)) {
            return 'No se pudo guardar la observación.';
        }

        return $observation;
    }

    public function markAsRead(int $userId, ?int $noveltyId = null, ?int $liquidationDocId = null): void
    {
        $table = TableRegistry::getTableLocator()->get('NoveltyObservations');
        $conditions = ['user_id !=' => $userId, 'is_read' => false];

        if ($noveltyId) {
            $conditions['novelty_id'] = $noveltyId;
        } elseif ($liquidationDocId) {
            $conditions['liquidation_doc_id'] = $liquidationDocId;
        } else {
            return;
        }

        $table->updateAll(['is_read' => true], $conditions);
    }

    public function getUnreadCount(int $userId, ?int $noveltyId = null, ?int $liquidationDocId = null): int
    {
        $table = TableRegistry::getTableLocator()->get('NoveltyObservations');
        $conditions = ['user_id !=' => $userId, 'is_read' => false];

        if ($noveltyId) {
            $conditions['novelty_id'] = $noveltyId;
        } elseif ($liquidationDocId) {
            $conditions['liquidation_doc_id'] = $liquidationDocId;
        } else {
            return 0;
        }

        return $table->find()->where($conditions)->count();
    }
}
