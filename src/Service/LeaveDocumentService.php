<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\ContractTypeConstants;
use Cake\ORM\TableRegistry;
use Laminas\Diactoros\UploadedFile;
use setasign\Fpdi\Tcpdf\Fpdi;
use Throwable;

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

    private StructuredLogger $logger;

    /**
     * Initialize the structured logger for leave-document operations.
     */
    public function __construct()
    {
        $this->logger = new StructuredLogger('LeaveDocument');
    }

    /**
     * Resolve the leave-document template for a novelty type, falling back to the
     * parent type for subtypes and matching by contract type (plus temporary org
     * for OBRA O LABOR DETERMINADA).
     *
     * @param int $noveltyTypeId Novelty type id (subtypes resolve via their parent).
     * @param string|null $contractType Employee contract type to match.
     * @param int|null $temporaryOrgId Temporary services organization id (only for obra o labor).
     * @return object|null The matching leave document template, or null when none applies.
     */
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

    /**
     * Validate and store an uploaded template file, returning its metadata and
     * detected page dimensions, or an error message.
     *
     * @param \Laminas\Diactoros\UploadedFile $file Uploaded template file (PNG/JPEG/PDF, max 5MB).
     * @return array Metadata (file_path, mime_type, page_width, page_height) or ['error' => string].
     */
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

    /**
     * Delete a stored template file from the webroot when it exists.
     *
     * @param string $filePath Webroot-relative path to the template file.
     * @return void
     */
    public function deleteTemplateFile(string $filePath): void
    {
        $fullPath = WWW_ROOT . $filePath;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    /**
     * Resolve and format a single template field value from a leave entity.
     *
     * @param object $leave Leave/novelty entity supplying the data.
     * @param string $fieldKey Dot-notation field key (e.g. employee.full_name).
     * @param string $fieldType Field type (text|date|time|boolean|image|check).
     * @param string|null $format Optional format (date/time pattern or boolean labels).
     * @return string Formatted value, or empty string when absent.
     */
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

    /**
     * Resolve a virtual paid/unpaid checkbox field to its mark or empty string.
     *
     * @param object $leave Leave entity carrying the is_paid flag.
     * @param string $fieldKey Virtual field key (paid_yes|paid_no).
     * @param string|null $format Optional mark character (defaults to X).
     * @return string The mark when the box applies, otherwise empty string.
     */
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

    /**
     * Render the leave-document PDF for a novelty by stamping template fields
     * (text and signature images) onto the template background.
     *
     * @param int $noveltyId Employee novelty id to render.
     * @param int $templateId Leave document template id to apply.
     * @return string Raw PDF binary string.
     */
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

    /**
     * Render a preview PDF of a template using sample data and signature placeholders.
     *
     * @param int $templateId Leave document template id to preview.
     * @return string Raw PDF binary string.
     */
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

    /**
     * Build an FPDI document sized to the template, using its file as the page
     * background (imported PDF page or drawn image).
     *
     * @param object $template Leave document template with page/background metadata.
     * @return \setasign\Fpdi\Tcpdf\Fpdi Prepared PDF instance with one page added.
     */
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
            } catch (Throwable $e) {
                // Lectura de PDF puede lanzar Exception o Error (FPDI, archivos corruptos).
                $this->logger->warning('pdf_dimensions_failed', [
                    'method' => __METHOD__,
                    'exception' => $e->getMessage(),
                ]);

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

    /**
     * Traverse a dot-notation key on an entity, resolving computed properties
     * (position/operation-center names, identification number).
     *
     * @param object $entity Root entity to read from.
     * @param string $key Dot-notation property path.
     * @return mixed The resolved value, or null when any segment is missing.
     */
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
