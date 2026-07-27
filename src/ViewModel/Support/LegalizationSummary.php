<?php
declare(strict_types=1);

namespace App\ViewModel\Support;

use App\Constants\AdvanceConstants;
use App\Model\Entity\AdvanceLegalization;
use App\View\Presentation\AdvancePresentation;
use App\View\Presentation\PipelineColorMap;

/**
 * Derivación de dominio del resumen de una legalización de anticipo, compartida
 * por AdvanceLegalizationViewModel (vista operativa) y AdvanceViewViewModel (hub
 * de consulta read-only). Fuente única de linkedTotal/diff/diffBadgeClass/split
 * de firmas/badge de estado para evitar drift entre ambos ViewModels.
 *
 * `linkedInvoices` debe ser re-iterable (array o ResultSet buffered de ->all());
 * se recorre una vez aquí para totales y de nuevo en el template.
 */
final readonly class LegalizationSummary
{
    public const CASE_LABELS = [
        AdvanceConstants::CASE_EXACTO => 'Exacto',
        AdvanceConstants::CASE_FALTANTE => 'Faltante',
        AdvanceConstants::CASE_SOBRANTE => 'Sobrante',
    ];

    public float $linkedTotal;
    public float $diff;
    public string $diffBadgeClass;
    public int $linkedCount;
    /**
     * @var \App\Model\Entity\AdvanceLegalizationSignature|null
     */
    public ?object $relationDocument;
    /**
     * @var array<int,\App\Model\Entity\AdvanceLegalizationSignature>
     */
    public array $signatureHistory;
    /**
     * @var list<\App\Model\Entity\AdvanceLegalizationDocument>
     */
    public array $documents;
    public int $totalDocs;
    /**
     * @var array{0:string,1:string}
     */
    public array $statusBadge;
    /**
     * @var list<string>
     */
    public array $casePipelineSteps;
    public string $caseLabel;
    public int $pipelineIdx;
    public string $pipelineVariant;

    /**
     * @param iterable $linkedInvoices Facturas tipo Legalización vinculadas al anticipo.
     */
    public function __construct(
        public AdvanceLegalization $leg,
        public float $advanceTotal,
        public iterable $linkedInvoices,
    ) {
        $linkedTotal = 0.0;
        $count = 0;
        foreach ($linkedInvoices as $li) {
            $linkedTotal += (float)$li->amount;
            $count++;
        }
        $this->linkedTotal = $linkedTotal;
        $this->linkedCount = $count;
        $this->diff = $advanceTotal - $linkedTotal;
        $this->diffBadgeClass = abs($this->diff) < 0.005
            ? 'pill-primary-soft'
            : ($this->diff > 0 ? 'pill-warning-soft' : 'pill-danger-soft');

        $relationDocument = null;
        $signatureHistory = [];
        if ($leg->advance_legalization_signatures) {
            $sigs = $leg->advance_legalization_signatures;
            usort($sigs, fn($a, $b) => $b->id <=> $a->id);
            foreach ($sigs as $sig) {
                if ($relationDocument === null && ($sig->isPending() || $sig->isSigned())) {
                    $relationDocument = $sig;
                } else {
                    $signatureHistory[] = $sig;
                }
            }
        }
        $this->relationDocument = $relationDocument;
        $this->signatureHistory = $signatureHistory;

        $this->documents = $leg->advance_legalization_documents ?? [];
        $this->totalDocs = count($this->documents);

        $this->statusBadge = [
            AdvanceConstants::STATUS_LABELS[$leg->status] ?? 'Desconocido',
            AdvancePresentation::STATUS_BADGES[$leg->status] ?? 'pill-muted',
        ];
        $this->casePipelineSteps = AdvanceConstants::PIPELINE_STATUSES_BY_CASE[$leg->case_type ?? '']
            ?? AdvanceConstants::PIPELINE_STATUSES_EXACTO;
        $this->caseLabel = $leg->case_type
            ? (self::CASE_LABELS[$leg->case_type] ?? $leg->case_type)
            : '';
        $idx = array_search($leg->status, $this->casePipelineSteps, true);
        $this->pipelineIdx = $idx === false ? -1 : (int)$idx;
        $this->pipelineVariant = PipelineColorMap::variant($leg->status);
    }
}
