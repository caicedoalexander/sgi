<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AssetConstants;
use App\Constants\Domain\Asset\MovementType;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

/**
 * Operaciones del inventario de activos. Cada operación es una transacción
 * atómica que (1) inserta el movimiento inmutable, (2) actualiza el activo y
 * (3) marca acta pendiente si el tipo de movimiento lo requiere.
 *
 * No es un pipeline: los movimientos son un log, no un flujo de aprobación.
 */
class AssetInventoryService
{
    /**
     * Registra un movimiento de ingreso. Deja el activo en disponible.
     *
     * @param int $assetId Activo.
     * @param array<string, mixed> $data Metadatos del movimiento.
     * @param int $userId Usuario que ejecuta.
     */
    public function registerIngress(int $assetId, array $data, int $userId): ServiceResult
    {
        return $this->_run($assetId, function (
            EntityInterface $asset,
            Table $assets,
            Table $movements,
        ) use (
            $assetId,
            $data,
            $userId,
): ServiceResult {
            if ($asset->status === AssetConstants::STATUS_DADO_DE_BAJA) {
                return ServiceResult::fail('No se puede ingresar un activo dado de baja.');
            }

            $asset->status = AssetConstants::STATUS_DISPONIBLE;

            $movement = $movements->newEntity(
                $this->_baseMovementData($assetId, AssetConstants::MOVEMENT_INGRESO, $userId, $data),
            );

            return $this->_commit($assets, $movements, $asset, $movement, 'Ingreso registrado.');
        });
    }

    /**
     * Entrega un activo disponible a un empleado. Marca acta pendiente.
     *
     * @param int $assetId Activo.
     * @param int $toEmployeeId Empleado responsable.
     * @param array<string, mixed> $data Metadatos del movimiento.
     * @param int $userId Usuario que ejecuta.
     */
    public function assign(int $assetId, int $toEmployeeId, array $data, int $userId): ServiceResult
    {
        return $this->_run($assetId, function (
            EntityInterface $asset,
            Table $assets,
            Table $movements,
        ) use (
            $assetId,
            $toEmployeeId,
            $data,
            $userId,
): ServiceResult {
            if ($asset->status !== AssetConstants::STATUS_DISPONIBLE) {
                return ServiceResult::fail('Solo se puede asignar un activo disponible.');
            }

            $fromEmployee = $asset->responsible_employee_id;
            $asset->responsible_employee_id = $toEmployeeId;
            $asset->status = AssetConstants::STATUS_ASIGNADO;

            $movement = $movements->newEntity(array_merge(
                $this->_baseMovementData($assetId, AssetConstants::MOVEMENT_ENTREGA, $userId, $data),
                ['from_employee_id' => $fromEmployee, 'to_employee_id' => $toEmployeeId],
            ));

            return $this->_commit($assets, $movements, $asset, $movement, 'Activo asignado correctamente.');
        });
    }

    /**
     * Presta un activo disponible a un empleado. Marca acta pendiente.
     *
     * @param int $assetId Activo.
     * @param int $toEmployeeId Empleado que recibe el préstamo.
     * @param array<string, mixed> $data Metadatos del movimiento.
     * @param int $userId Usuario que ejecuta.
     */
    public function lend(int $assetId, int $toEmployeeId, array $data, int $userId): ServiceResult
    {
        return $this->_run($assetId, function (
            EntityInterface $asset,
            Table $assets,
            Table $movements,
        ) use (
            $assetId,
            $toEmployeeId,
            $data,
            $userId,
): ServiceResult {
            if ($asset->status !== AssetConstants::STATUS_DISPONIBLE) {
                return ServiceResult::fail('Solo se puede prestar un activo disponible.');
            }

            $fromEmployee = $asset->responsible_employee_id;
            $asset->responsible_employee_id = $toEmployeeId;
            $asset->status = AssetConstants::STATUS_PRESTADO;

            $movement = $movements->newEntity(array_merge(
                $this->_baseMovementData($assetId, AssetConstants::MOVEMENT_PRESTAMO, $userId, $data),
                ['from_employee_id' => $fromEmployee, 'to_employee_id' => $toEmployeeId],
            ));

            return $this->_commit($assets, $movements, $asset, $movement, 'Activo prestado correctamente.');
        });
    }

    /**
     * Devuelve un activo asignado o prestado. Limpia el responsable y lo deja
     * disponible. Marca acta pendiente.
     *
     * @param int $assetId Activo.
     * @param array<string, mixed> $data Metadatos del movimiento.
     * @param int $userId Usuario que ejecuta.
     */
    public function returnAsset(int $assetId, array $data, int $userId): ServiceResult
    {
        return $this->_run($assetId, function (
            EntityInterface $asset,
            Table $assets,
            Table $movements,
        ) use (
            $assetId,
            $data,
            $userId,
): ServiceResult {
            if (!in_array($asset->status, [AssetConstants::STATUS_ASIGNADO, AssetConstants::STATUS_PRESTADO], true)) {
                return ServiceResult::fail('Solo se puede devolver un activo asignado o prestado.');
            }

            $fromEmployee = $asset->responsible_employee_id;
            $asset->responsible_employee_id = null;
            $asset->status = AssetConstants::STATUS_DISPONIBLE;

            $movement = $movements->newEntity(array_merge(
                $this->_baseMovementData($assetId, AssetConstants::MOVEMENT_DEVOLUCION, $userId, $data),
                ['from_employee_id' => $fromEmployee, 'to_employee_id' => null],
            ));

            return $this->_commit($assets, $movements, $asset, $movement, 'Activo devuelto correctamente.');
        });
    }

    /**
     * Traslada un activo a otro centro de operación. No cambia el estado.
     *
     * @param int $assetId Activo.
     * @param int $toOperationCenterId Centro de operación destino.
     * @param array<string, mixed> $data Metadatos del movimiento.
     * @param int $userId Usuario que ejecuta.
     */
    public function transfer(int $assetId, int $toOperationCenterId, array $data, int $userId): ServiceResult
    {
        return $this->_run($assetId, function (
            EntityInterface $asset,
            Table $assets,
            Table $movements,
        ) use (
            $assetId,
            $toOperationCenterId,
            $data,
            $userId,
): ServiceResult {
            if ($asset->status === AssetConstants::STATUS_DADO_DE_BAJA) {
                return ServiceResult::fail('No se puede trasladar un activo dado de baja.');
            }

            $fromCenter = $asset->operation_center_id;
            $asset->operation_center_id = $toOperationCenterId;

            $movement = $movements->newEntity(array_merge(
                $this->_baseMovementData($assetId, AssetConstants::MOVEMENT_TRASLADO, $userId, $data),
                ['from_operation_center_id' => $fromCenter, 'to_operation_center_id' => $toOperationCenterId],
            ));

            return $this->_commit($assets, $movements, $asset, $movement, 'Activo trasladado correctamente.');
        });
    }

    /**
     * Da de baja un activo (estado terminal). Limpia el responsable. Marca acta
     * pendiente.
     *
     * @param int $assetId Activo.
     * @param array<string, mixed> $data Metadatos del movimiento.
     * @param int $userId Usuario que ejecuta.
     */
    public function dispose(int $assetId, array $data, int $userId): ServiceResult
    {
        return $this->_run($assetId, function (
            EntityInterface $asset,
            Table $assets,
            Table $movements,
        ) use (
            $assetId,
            $data,
            $userId,
): ServiceResult {
            if ($asset->status === AssetConstants::STATUS_DADO_DE_BAJA) {
                return ServiceResult::fail('El activo ya está dado de baja.');
            }

            $fromEmployee = $asset->responsible_employee_id;
            $asset->responsible_employee_id = null;
            $asset->status = AssetConstants::STATUS_DADO_DE_BAJA;

            $movement = $movements->newEntity(array_merge(
                $this->_baseMovementData($assetId, AssetConstants::MOVEMENT_BAJA, $userId, $data),
                ['from_employee_id' => $fromEmployee],
            ));

            return $this->_commit($assets, $movements, $asset, $movement, 'Activo dado de baja.');
        });
    }

    /**
     * Abre la transacción, lee el activo con lock y delega la operación.
     * `$operation` recibe (Asset, AssetsTable, AssetMovementsTable) y retorna
     * ServiceResult; success=true commitea, false hace rollback.
     *
     * @param int $assetId Activo.
     * @param callable(\Cake\Datasource\EntityInterface, \Cake\ORM\Table, \Cake\ORM\Table): \App\Service\ServiceResult $operation
     */
    protected function _run(int $assetId, callable $operation): ServiceResult
    {
        $assets = TableRegistry::getTableLocator()->get('Assets');
        $movements = TableRegistry::getTableLocator()->get('AssetMovements');
        $connection = $assets->getConnection();

        $result = null;

        $connection->transactional(function () use ($assets, $movements, $assetId, $operation, &$result): bool {
            $asset = $assets->find()->where(['Assets.id' => $assetId])->epilog('FOR UPDATE')->first();
            if ($asset === null) {
                $result = ServiceResult::fail('Activo no encontrado.');

                return false;
            }

            $result = $operation($asset, $assets, $movements);

            return $result->success;
        });

        return $result ?? ServiceResult::fail('No se pudo completar la operación.');
    }

    /**
     * Campos comunes de un movimiento, con acta pendiente si el tipo lo exige.
     *
     * @param array<string, mixed> $data Metadatos.
     * @return array<string, mixed>
     */
    protected function _baseMovementData(int $assetId, string $movementType, int $userId, array $data): array
    {
        $requiresActa = MovementType::from($movementType)->requiresActa();

        return [
            'asset_id' => $assetId,
            'movement_type' => $movementType,
            'performed_by_user_id' => $userId,
            'movement_date' => $data['movement_date'] ?? date('Y-m-d H:i:s'),
            'reason' => $data['reason'] ?? null,
            'source' => $data['source'] ?? AssetConstants::SOURCE_WEB,
            'requested_by_phone' => $data['requested_by_phone'] ?? null,
            'requested_by_employee_id' => $data['requested_by_employee_id'] ?? null,
            'acta_status' => $requiresActa ? AssetConstants::ACTA_PENDIENTE : null,
        ];
    }

    /**
     * Persiste activo + movimiento dentro de la transacción abierta por _run.
     */
    protected function _commit(
        Table $assets,
        Table $movements,
        EntityInterface $asset,
        EntityInterface $movement,
        string $successMessage,
    ): ServiceResult {
        if (!$assets->save($asset)) {
            return ServiceResult::fail($this->_saveError('No se pudo actualizar el activo.', $asset->getErrors()));
        }
        if (!$movements->save($movement)) {
            $error = $this->_saveError('No se pudo registrar el movimiento.', $movement->getErrors());

            return ServiceResult::fail($error);
        }

        return ServiceResult::ok([
            'asset' => $asset,
            'movement' => $movement,
            'message' => $successMessage,
        ]);
    }

    /**
     * Compone un mensaje de error legible incluyendo detalles de validación.
     *
     * @param array<string, mixed> $entityErrors Errores de Entity::getErrors().
     */
    protected function _saveError(string $base, array $entityErrors): string
    {
        $details = [];
        foreach ($entityErrors as $field => $fieldErrors) {
            foreach ((array)$fieldErrors as $msg) {
                if (is_string($msg) && $msg !== '') {
                    $details[] = sprintf('%s: %s', $field, $msg);
                }
            }
        }

        return $details === [] ? $base : ($base . ' ' . implode(', ', $details));
    }
}
