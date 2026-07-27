<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AssetConstants;
use App\Constants\ConsumableConstants;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

/**
 * Control de stock de consumibles. Cada operación es atómica: lee el consumible
 * con lock, recalcula el stock, lo persiste y registra el movimiento inmutable
 * con el balance resultante.
 */
class ConsumableStockService
{
    /**
     * @param array<string, mixed> $data Metadatos opcionales (reason, movement_date, source, etc.).
     */
    public function registerIngress(int $consumableId, int $quantity, array $data, int $userId): ServiceResult
    {
        if ($quantity <= 0) {
            return ServiceResult::fail('La cantidad debe ser mayor a cero.');
        }

        return $this->_run($consumableId, function (
            EntityInterface $consumable,
            Table $consumables,
            Table $movements,
        ) use (
            $consumableId,
            $quantity,
            $data,
            $userId,
): ServiceResult {
            $newStock = (int)$consumable->current_stock + $quantity;
            $consumable->current_stock = $newStock;

            $movement = $movements->newEntity($this->_movementData(
                $consumableId,
                ConsumableConstants::MOVEMENT_INGRESO,
                $quantity,
                $newStock,
                $userId,
                $data,
            ));

            return $this->_commit($consumables, $movements, $consumable, $movement, 'Ingreso de stock registrado.');
        });
    }

    /**
     * @param array<string, mixed> $data Metadatos opcionales (reason, movement_date, source, etc.).
     */
    public function registerOutput(int $consumableId, int $quantity, array $data, int $userId): ServiceResult
    {
        if ($quantity <= 0) {
            return ServiceResult::fail('La cantidad debe ser mayor a cero.');
        }

        return $this->_run($consumableId, function (
            EntityInterface $consumable,
            Table $consumables,
            Table $movements,
        ) use (
            $consumableId,
            $quantity,
            $data,
            $userId,
): ServiceResult {
            $newStock = (int)$consumable->current_stock - $quantity;
            if ($newStock < 0) {
                return ServiceResult::fail('Stock insuficiente para la salida solicitada.');
            }
            $consumable->current_stock = $newStock;

            $movement = $movements->newEntity($this->_movementData(
                $consumableId,
                ConsumableConstants::MOVEMENT_SALIDA,
                $quantity,
                $newStock,
                $userId,
                $data,
            ));

            return $this->_commit($consumables, $movements, $consumable, $movement, 'Salida de stock registrada.');
        });
    }

    /**
     * @param array<string, mixed> $data Metadatos opcionales (reason, movement_date, source, etc.).
     */
    public function adjust(int $consumableId, int $newStock, array $data, int $userId): ServiceResult
    {
        if ($newStock < 0) {
            return ServiceResult::fail('El stock ajustado no puede ser negativo.');
        }

        return $this->_run($consumableId, function (
            EntityInterface $consumable,
            Table $consumables,
            Table $movements,
        ) use (
            $consumableId,
            $newStock,
            $data,
            $userId,
): ServiceResult {
            $delta = $newStock - (int)$consumable->current_stock;
            $consumable->current_stock = $newStock;

            $movement = $movements->newEntity($this->_movementData(
                $consumableId,
                ConsumableConstants::MOVEMENT_AJUSTE,
                $delta,
                $newStock,
                $userId,
                $data,
            ));

            return $this->_commit($consumables, $movements, $consumable, $movement, 'Stock ajustado.');
        });
    }

    /**
     * @param callable(\Cake\Datasource\EntityInterface, \Cake\ORM\Table, \Cake\ORM\Table): \App\Service\ServiceResult $operation
     */
    protected function _run(int $consumableId, callable $operation): ServiceResult
    {
        $consumables = TableRegistry::getTableLocator()->get('Consumables');
        $movements = TableRegistry::getTableLocator()->get('ConsumableMovements');
        $connection = $consumables->getConnection();

        $result = null;

        $connection->transactional(function () use (
            $consumables,
            $movements,
            $consumableId,
            $operation,
            &$result,
        ): bool {
            $consumable = $consumables->find()
                ->where(['Consumables.id' => $consumableId])
                ->epilog('FOR UPDATE')
                ->first();
            if ($consumable === null) {
                $result = ServiceResult::fail('Consumible no encontrado.');

                return false;
            }

            $result = $operation($consumable, $consumables, $movements);

            return $result->success;
        });

        return $result ?? ServiceResult::fail('No se pudo completar la operación.');
    }

    /**
     * @param array<string, mixed> $data Metadatos.
     * @return array<string, mixed>
     */
    protected function _movementData(
        int $consumableId,
        string $movementType,
        int $quantity,
        int $balanceAfter,
        int $userId,
        array $data,
    ): array {
        return [
            'consumable_id' => $consumableId,
            'movement_type' => $movementType,
            'quantity' => $quantity,
            'balance_after' => $balanceAfter,
            'reason' => $data['reason'] ?? null,
            'related_asset_id' => $data['related_asset_id'] ?? null,
            'movement_date' => $data['movement_date'] ?? date('Y-m-d H:i:s'),
            'performed_by_user_id' => $userId,
            'requested_by_phone' => $data['requested_by_phone'] ?? null,
            'source' => $data['source'] ?? AssetConstants::SOURCE_WEB,
        ];
    }

    /**
     * Persiste el consumible y el movimiento; retorna ServiceResult coherente.
     */
    protected function _commit(
        Table $consumables,
        Table $movements,
        EntityInterface $consumable,
        EntityInterface $movement,
        string $successMessage,
    ): ServiceResult {
        if (!$consumables->save($consumable)) {
            return ServiceResult::fail('No se pudo actualizar el stock del consumible.');
        }
        if (!$movements->save($movement)) {
            return ServiceResult::fail('No se pudo registrar el movimiento de stock.');
        }

        return ServiceResult::ok([
            'consumable' => $consumable,
            'movement' => $movement,
            'message' => $successMessage,
        ]);
    }
}
