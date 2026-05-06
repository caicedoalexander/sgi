<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\AdvanceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\Service\Trait\DocumentUploadTrait;
use Cake\ORM\TableRegistry;
use Laminas\Diactoros\UploadedFile;

/**
 * Centraliza la gestión de archivos relacionada a la legalización de anticipos.
 *
 * Extraído de `AdvanceLegalizationService` para alinear con la base canónica
 * del audit `docs/audits/flow-structure-audit-2026-05-06.md` (Plan D).
 */
class AdvanceLegalizationDocumentService
{
    use DocumentUploadTrait;

    /**
     * Save the relation-of-invoices document; supersedes any pending signature row.
     *
     * Mantiene la limpieza de huérfanos en `webroot/uploads/` (audit MA-004) y
     * la validación `$leg->canUploadRelationDocument()`.
     */
    public function attachRelationDocument(AdvanceLegalization $leg, UploadedFile $file, int $userId): ServiceResult
    {
        if (!$leg->canUploadRelationDocument()) {
            return ServiceResult::fail('Solo se puede subir el documento en Validación o Revisión y Firmas.');
        }

        $sigTable = TableRegistry::getTableLocator()->get('AdvanceLegalizationSignatures');
        $legTable = TableRegistry::getTableLocator()->get('AdvanceLegalizations');

        $result = null;
        $sigTable->getConnection()->transactional(
            function () use ($leg, $file, $userId, $sigTable, $legTable, &$result): bool {
                $upload = $this->uploadAndSave(
                    $file,
                    'AdvanceLegalizationSignatures',
                    'advances/' . $leg->id,
                    'leg_',
                    [
                        'legalization_id' => $leg->id,
                        'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
                    ],
                );

                if (is_string($upload)) {
                    $result = ServiceResult::fail($upload);

                    return false;
                }

                // Borrar archivos físicos de los pendientes anteriores antes del
                // deleteAll para no dejar huérfanos en webroot/uploads/ (audit MA-004).
                $stalePending = $sigTable->find()
                    ->where([
                        'legalization_id' => $leg->id,
                        'id !=' => $upload->id,
                        'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
                    ])
                    ->all();
                foreach ($stalePending as $stale) {
                    if (!empty($stale->file_path)) {
                        $diskPath = WWW_ROOT . str_replace('/', DS, $stale->file_path);
                        if (file_exists($diskPath)) {
                            @unlink($diskPath);
                        }
                    }
                }

                $sigTable->deleteAll([
                    'legalization_id' => $leg->id,
                    'id !=' => $upload->id,
                    'signature_status' => AdvanceConstants::SIGNATURE_PENDING,
                ]);

                $leg->updated_by = $userId;
                if (!$legTable->save($leg)) {
                    $result = ServiceResult::fail(
                        'No se pudo actualizar la legalización: ' . $this->_firstErrorMessage($leg->getErrors()),
                    );

                    return false;
                }

                $result = ServiceResult::ok($upload);

                return true;
            },
        );

        return $result ?? ServiceResult::fail('La transacción falló.');
    }

    /**
     * Sube el comprobante de consignación del faltante.
     *
     * Devuelve `ServiceResult::ok($filePath)` con la ruta relativa del archivo
     * subido, o `ServiceResult::fail($error)` si la validación falla.
     */
    public function attachShortageReceipt(AdvanceLegalization $leg, UploadedFile $file): ServiceResult
    {
        $info = $this->validateAndMoveUpload(
            $file,
            'advances/' . $leg->id,
            'shortage_',
        );
        if (is_string($info)) {
            return ServiceResult::fail($info);
        }

        return ServiceResult::ok($info['file_path']);
    }

    /**
     * @param array<string, mixed> $errors Errores de CakePHP entity->getErrors().
     */
    private function _firstErrorMessage(array $errors): string
    {
        $flat = [];
        array_walk_recursive($errors, function ($message) use (&$flat): void {
            if (is_string($message) && $message !== '') {
                $flat[] = $message;
            }
        });

        return $flat[0] ?? 'Error de validación.';
    }
}
