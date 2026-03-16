<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\ContractTypeConstants;
use Cake\ORM\TableRegistry;
use Exception;
use Laminas\Diactoros\UploadedFile;
use setasign\Fpdi\Tcpdf\Fpdi;

class LeaveDocumentService
{
    private const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5 MB

    private const ALLOWED_MIMES = [
        'image/png',
        'image/jpeg',
        'application/pdf',
    ];

    public const AVAILABLE_FIELDS = [
        // Empleado
        'employee.full_name' => ['label' => 'Nombre Empleado', 'type' => 'text', 'group' => 'Empleado'],
        'employee.identification_number' => ['label' => 'Cédula', 'type' => 'text', 'group' => 'Empleado'],
        'employee.position_name' => ['label' => 'Cargo', 'type' => 'text', 'group' => 'Empleado'],
        'employee.operation_center_name' => ['label' => 'Centro de Operación', 'type' => 'text', 'group' => 'Empleado'],

        // Novedad
        'novelty_type.name' => ['label' => 'Tipo de Novedad', 'type' => 'text', 'group' => 'Novedad'],
        'reason' => ['label' => 'Motivo', 'type' => 'text', 'group' => 'Novedad'],
        'observations' => ['label' => 'Observaciones', 'type' => 'text', 'group' => 'Novedad'],

        // Fechas y horarios
        'start_date' => ['label' => 'Fecha Inicio', 'type' => 'date', 'group' => 'Fechas'],
        'end_date' => ['label' => 'Fecha Fin', 'type' => 'date', 'group' => 'Fechas'],
        'permission_date' => ['label' => 'Fecha del Permiso', 'type' => 'date', 'group' => 'Fechas'],
        'filing_date' => ['label' => 'Fecha Diligenciamiento', 'type' => 'date', 'group' => 'Fechas'],
        'schedule_type' => ['label' => 'Horario', 'type' => 'text', 'group' => 'Fechas'],
        'start_time' => ['label' => 'Hora Salida', 'type' => 'time', 'group' => 'Fechas'],
        'end_time' => ['label' => 'Hora Entrada', 'type' => 'time', 'group' => 'Fechas'],

        // Clasificación
        'paid_yes' => ['label' => 'Remunerado (X)', 'type' => 'check', 'group' => 'Clasificación'],
        'paid_no' => ['label' => 'No Remunerado (X)', 'type' => 'check', 'group' => 'Clasificación'],
        'status' => ['label' => 'Estado', 'type' => 'text', 'group' => 'Clasificación'],

        // Gestión
        'registered_by_user.full_name' => ['label' => 'Registrado Por', 'type' => 'text', 'group' => 'Gestión'],
        'approved_by_user.full_name' => ['label' => 'Aprobado Por', 'type' => 'text', 'group' => 'Gestión'],
        'approved_at' => ['label' => 'Fecha Aprobación', 'type' => 'date', 'group' => 'Gestión'],

        // Firmas
        'employee_signature' => ['label' => 'Firma Funcionario', 'type' => 'image', 'group' => 'Firma'],
        'coordinator_signature' => ['label' => 'Firma Coordinador', 'type' => 'image', 'group' => 'Firma'],
    ];

    public function resolveTemplate(int $noveltyTypeId, ?string $contractType, ?int $temporaryOrgId): ?object
    {
        // If this is a subtype, resolve using the parent's templates
        $noveltyTypesTable = TableRegistry::getTableLocator()->get('NoveltyTypes');
        $noveltyType = $noveltyTypesTable->get($noveltyTypeId);
        $lookupTypeId = $noveltyType->parent_id ?? $noveltyTypeId;

        $table = TableRegistry::getTableLocator()->get('NoveltyTypeContractTemplates');

        // Try exact match (with org for OBRA O LABOR DETERMINADA)
        if ($contractType === ContractTypeConstants::OBRA_LABOR && $temporaryOrgId) {
            $match = $table->find()
                ->contain(['LeaveDocumentTemplates'])
                ->where([
                    'NoveltyTypeContractTemplates.novelty_type_id' => $lookupTypeId,
                    'NoveltyTypeContractTemplates.contract_type' => $contractType,
                    'NoveltyTypeContractTemplates.temporary_organization_id' => $temporaryOrgId,
                ])
                ->first();

            if ($match && $match->leave_document_template) {
                return $match->leave_document_template;
            }
        }

        // Try match by contract type only (org IS NULL)
        if ($contractType) {
            $match = $table->find()
                ->contain(['LeaveDocumentTemplates'])
                ->where([
                    'NoveltyTypeContractTemplates.novelty_type_id' => $lookupTypeId,
                    'NoveltyTypeContractTemplates.contract_type' => $contractType,
                    'NoveltyTypeContractTemplates.temporary_organization_id IS' => null,
                ])
                ->first();

            if ($match && $match->leave_document_template) {
                return $match->leave_document_template;
            }
        }

        return null;
    }

    public function uploadTemplate(UploadedFile $file): array
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return ['error' => 'No se recibió ningún archivo válido.'];
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return ['error' => 'El archivo excede el tamaño máximo de 5MB.'];
        }

        $mimeType = $file->getClientMediaType();
        if (!in_array($mimeType, self::ALLOWED_MIMES)) {
            return ['error' => 'Tipo de archivo no permitido. Use PNG, JPEG o PDF.'];
        }

        $uploadDir = WWW_ROOT . 'uploads' . DS . 'leave_templates';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $extension = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
        $uniqueName = 'tpl_' . uniqid() . '.' . $extension;
        $filePath = $uploadDir . DS . $uniqueName;

        $file->moveTo($filePath);

        // Detect dimensions from the file
        $dimensions = $this->detectFileDimensions($filePath, $mimeType);

        return [
            'file_path' => 'uploads/leave_templates/' . $uniqueName,
            'mime_type' => $mimeType,
            'page_width' => $dimensions['width'],
            'page_height' => $dimensions['height'],
        ];
    }

    public function deleteTemplateFile(string $filePath): void
    {
        $fullPath = WWW_ROOT . $filePath;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    public function resolveFieldValue(object $leave, string $fieldKey, string $fieldType, ?string $format): string
    {
        // Handle virtual check fields
        if ($fieldType === 'check') {
            return $this->resolveCheckField($leave, $fieldKey, $format);
        }

        $value = $this->getNestedValue($leave, $fieldKey);

        if ($value === null || $value === '') {
            return '';
        }

        switch ($fieldType) {
            case 'date':
                if (is_object($value) && method_exists($value, 'format')) {
                    return $value->format($format ?: 'd/m/Y');
                }

                return (string)$value;

            case 'time':
                if (is_object($value) && method_exists($value, 'format')) {
                    return $value->format($format ?: 'H:i');
                }

                return (string)$value;

            case 'boolean':
                $labels = $format ? explode('|', $format) : ['Sí', 'No'];

                return $value ? ($labels[0] ?? 'Sí') : ($labels[1] ?? 'No');

            case 'image':
                return (string)$value;

            default:
                return (string)$value;
        }
    }

    private function resolveCheckField(object $leave, string $fieldKey, ?string $format): string
    {
        $mark = $format ?: 'X';

        switch ($fieldKey) {
            case 'paid_yes':
                return !empty($leave->is_paid) ? $mark : '';
            case 'paid_no':
                return empty($leave->is_paid) ? $mark : '';
            default:
                return '';
        }
    }

    public function generatePdf(int $noveltyId, int $templateId): string
    {
        $noveltiesTable = TableRegistry::getTableLocator()->get('EmployeeNovelties');
        $leave = $noveltiesTable->get($noveltyId, contain: [
            'Employees.Positions',
            'Employees.OperationCenters',
            'NoveltyTypes',
            'ApprovedByUsers',
            'RegisteredByUsers',
        ]);

        $templatesTable = TableRegistry::getTableLocator()->get('LeaveDocumentTemplates');
        $template = $templatesTable->get($templateId, contain: ['LeaveTemplateFields']);

        $pdf = $this->createPdfWithBackground($template);

        $pdf->SetFont('helvetica', '', 10);

        foreach ($template->leave_template_fields as $field) {
            $fieldType = $field->field_type ?: 'text';
            $value = $this->resolveFieldValue($leave, $field->field_key, $fieldType, $field->format);

            if ($value === '') {
                continue;
            }

            if ($fieldType === 'image') {
                $imgPath = WWW_ROOT . $value;
                if (file_exists($imgPath)) {
                    $w = $field->width ? (float)$field->width : 30;
                    $h = $field->height ? (float)$field->height : 15;
                    $pdf->Image($imgPath, (float)$field->x, (float)$field->y, $w, $h);
                }
                continue;
            }

            $fontSize = $field->font_size ?: 10;
            $fontStyle = $field->font_style ?: '';
            $pdf->SetFont('helvetica', $fontStyle, $fontSize);

            $cellW = $field->width ? (float)$field->width : 0;
            $cellH = $field->height ? (float)$field->height : (float)($fontSize * 0.5);

            $pdf->SetXY((float)$field->x, (float)$field->y);
            $pdf->Cell($cellW, $cellH, $value, 0, 0, $field->alignment ?: 'L');
        }

        return $pdf->Output('', 'S');
    }

    public function generatePreviewPdf(int $templateId): string
    {
        $templatesTable = TableRegistry::getTableLocator()->get('LeaveDocumentTemplates');
        $template = $templatesTable->get($templateId, contain: ['LeaveTemplateFields']);

        $pdf = $this->createPdfWithBackground($template);

        $pdf->SetFont('helvetica', '', 10);

        $sampleData = [
            'employee.full_name' => 'Juan Carlos Pérez López',
            'employee.identification_number' => '1.234.567.890',
            'employee.position_name' => 'Analista de Sistemas',
            'employee.operation_center_name' => 'Sede Principal',
            'novelty_type.name' => 'Permiso Personal',
            'reason' => 'Cita médica programada',
            'observations' => 'Sin observaciones',
            'start_date' => '28/02/2026',
            'end_date' => '01/03/2026',
            'permission_date' => '28/02/2026',
            'filing_date' => '27/02/2026',
            'schedule_type' => 'Por horas',
            'start_time' => '10:00',
            'end_time' => '14:00',
            'paid_yes' => 'X',
            'paid_no' => '',
            'status' => 'Aprobado',
            'registered_by_user.full_name' => 'María García',
            'approved_by_user.full_name' => 'Carlos Rodríguez',
            'approved_at' => '28/02/2026 09:30',
            'employee_signature' => '',
            'coordinator_signature' => '',
        ];

        foreach ($template->leave_template_fields as $field) {
            $fieldType = $field->field_type ?: 'text';

            if ($fieldType === 'image') {
                $w = $field->width ? (float)$field->width : 30;
                $h = $field->height ? (float)$field->height : 15;
                $pdf->Rect((float)$field->x, (float)$field->y, $w, $h);
                $pdf->SetXY((float)$field->x, (float)$field->y);
                $pdf->SetFont('helvetica', 'I', 7);
                $pdf->Cell($w, $h, '[Firma]', 0, 0, 'C');
                continue;
            }

            $value = $sampleData[$field->field_key] ?? '[' . ($field->label ?: $field->field_key) . ']';

            $fontSize = $field->font_size ?: 10;
            $fontStyle = $field->font_style ?: '';
            $pdf->SetFont('helvetica', $fontStyle, $fontSize);

            $cellW = $field->width ? (float)$field->width : 0;
            $cellH = $field->height ? (float)$field->height : (float)($fontSize * 0.5);

            $pdf->SetXY((float)$field->x, (float)$field->y);
            $pdf->Cell($cellW, $cellH, $value, 0, 0, $field->alignment ?: 'L');
        }

        return $pdf->Output('', 'S');
    }

    private function createPdfWithBackground(object $template): Fpdi
    {
        $pageW = (float)$template->page_width;
        $pageH = (float)$template->page_height;
        $orientation = ($template->orientation ?? 'P') === 'L' ? 'L' : 'P';

        $pdf = new Fpdi($orientation, 'mm', [$pageW, $pageH], true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);

        $bgPath = WWW_ROOT . $template->file_path;
        $isPdf = ($template->mime_type === 'application/pdf');

        if ($isPdf && file_exists($bgPath)) {
            $pdf->setSourceFile($bgPath);
            $tplIdx = $pdf->importPage(1);
            $pdf->AddPage($orientation, [$pageW, $pageH]);
            $pdf->useTemplate($tplIdx, 0, 0, $pageW, $pageH);
        } else {
            $pdf->AddPage($orientation, [$pageW, $pageH]);
            if (file_exists($bgPath)) {
                $pdf->Image($bgPath, 0, 0, $pageW, $pageH);
            }
        }

        return $pdf;
    }

    /**
     * Detect file dimensions in mm.
     * For images: converts pixels to mm at 96 DPI.
     * For PDFs: reads the first page mediabox via FPDI.
     */
    private function detectFileDimensions(string $filePath, string $mimeType): array
    {
        // Default: Letter size
        $defaultW = 215.9;
        $defaultH = 279.4;

        if ($mimeType === 'application/pdf') {
            try {
                $fpdi = new Fpdi();
                $fpdi->setSourceFile($filePath);
                $size = $fpdi->getTemplateSize($fpdi->importPage(1));

                return [
                    'width' => round((float)$size['width'], 2),
                    'height' => round((float)$size['height'], 2),
                ];
            } catch (Exception $e) {
                return ['width' => $defaultW, 'height' => $defaultH];
            }
        }

        // Image: getimagesize returns pixels
        $info = @getimagesize($filePath);
        if ($info && $info[0] > 0 && $info[1] > 0) {
            $pxW = $info[0];
            $pxH = $info[1];
            // Convert px to mm at 96 DPI (1 inch = 25.4mm, 96 px/inch)
            $mmW = round($pxW * 25.4 / 96, 2);
            $mmH = round($pxH * 25.4 / 96, 2);

            return ['width' => $mmW, 'height' => $mmH];
        }

        return ['width' => $defaultW, 'height' => $defaultH];
    }

    private function getNestedValue(object $entity, string $key): mixed
    {
        $parts = explode('.', $key);

        $current = $entity;
        foreach ($parts as $part) {
            if ($current === null) {
                return null;
            }

            // Handle special computed properties
            if ($part === 'position_name' && is_object($current) && isset($current->position)) {
                return $current->position->name ?? null;
            }
            if ($part === 'operation_center_name' && is_object($current) && isset($current->operation_center)) {
                return $current->operation_center->name ?? null;
            }
            if ($part === 'identification_number' && is_object($current)) {
                return $current->document_number ?? null;
            }

            if (is_object($current) && isset($current->{$part})) {
                $current = $current->{$part};
            } else {
                return null;
            }
        }

        return $current;
    }
}
