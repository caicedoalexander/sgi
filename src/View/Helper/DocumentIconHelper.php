<?php
declare(strict_types=1);

namespace App\View\Helper;

use Cake\View\Helper;

class DocumentIconHelper extends Helper
{
    /**
     * Reglas MIME → ícono/color. Fuente única para PHP y para el cliente JS
     * (vía `rulesJson()` que se inyecta como `<script type="application/json"
     * id="sgi-doc-icon-rules">` en el layout — ver audit CR-202).
     *
     * Se evalúan en orden; el primer match gana. La última entrada (`matches: []`)
     * es el default y debe quedar al final.
     *
     * Cada entrada:
     *  - matches: substrings que se buscan con str_contains en el MIME
     *  - icon: clase Bootstrap Icons
     *  - color: hex o var() CSS
     *  - label: badge label para typeLabel()
     */
    private const MIME_RULES = [
        ['matches' => ['pdf'],                            'icon' => 'bi-file-earmark-pdf',   'color' => '#dc3545',              'label' => 'PDF'],
        ['matches' => ['image/jpeg', 'image/jpg'],        'icon' => 'bi-file-earmark-image', 'color' => '#0dcaf0',              'label' => 'JPG'],
        ['matches' => ['image/png'],                      'icon' => 'bi-file-earmark-image', 'color' => '#0dcaf0',              'label' => 'PNG'],
        ['matches' => ['image'],                          'icon' => 'bi-file-earmark-image', 'color' => '#0dcaf0',              'label' => 'IMG'],
        ['matches' => ['wordprocessingml', 'msword'],     'icon' => 'bi-file-earmark-word',  'color' => '#0d6efd',              'label' => 'WORD'],
        ['matches' => ['spreadsheet', 'excel'],           'icon' => 'bi-file-earmark-excel', 'color' => 'var(--primary-color)', 'label' => 'EXCEL'],
        ['matches' => ['text/plain'],                     'icon' => 'bi-file-earmark-text',  'color' => '#aaa',                 'label' => 'TXT'],
        ['matches' => [],                                 'icon' => 'bi-file-earmark',       'color' => '#aaa',                 'label' => 'ARCH.'],
    ];

    /**
     * Match exacto por extensión final del archivo (cuando no hay MIME).
     */
    private const EXT_RULES = [
        ['exts' => ['pdf'],                       'icon' => 'bi-file-earmark-pdf',   'color' => '#dc3545'],
        ['exts' => ['jpg', 'jpeg', 'png', 'gif', 'webp'], 'icon' => 'bi-file-earmark-image', 'color' => '#0dcaf0'],
        ['exts' => ['doc', 'docx'],               'icon' => 'bi-file-earmark-word',  'color' => '#0d6efd'],
        ['exts' => ['xls', 'xlsx', 'csv'],        'icon' => 'bi-file-earmark-excel', 'color' => 'var(--primary-color)'],
        ['exts' => ['txt'],                       'icon' => 'bi-file-earmark-text',  'color' => '#aaa'],
    ];

    private function resolveByMime(?string $mime): array
    {
        $mime ??= '';
        foreach (self::MIME_RULES as $rule) {
            if ($rule['matches'] === []) {
                return $rule;
            }
            foreach ($rule['matches'] as $needle) {
                if (str_contains($mime, $needle)) {
                    return $rule;
                }
            }
        }

        return end(self::MIME_RULES); // unreachable: empty matches actúa como default.
    }

    public function iconClass(?string $mime): string
    {
        return $this->resolveByMime($mime)['icon'];
    }

    public function iconColor(?string $mime): string
    {
        return $this->resolveByMime($mime)['color'];
    }

    public function typeLabel(?string $mime): string
    {
        return $this->resolveByMime($mime)['label'];
    }

    public function badgeClass(string $type): string
    {
        return match ($type) {
            'PDF' => 'pill-danger-soft',
            'JPG', 'PNG', 'IMG' => 'pill-info-soft',
            'WORD' => 'pill-muted',
            'EXCEL' => 'pill-primary-soft',
            default => 'pill-muted',
        };
    }

    private function resolveByExt(?string $name): ?array
    {
        $name = strtolower($name ?? '');
        $dotPos = strrpos($name, '.');
        if ($dotPos === false) {
            return null;
        }
        $ext = substr($name, $dotPos + 1);
        foreach (self::EXT_RULES as $rule) {
            if (in_array($ext, $rule['exts'], true)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Resuelve clase de ícono por nombre de archivo (cuando no se tiene el MIME).
     */
    public function iconClassByName(?string $name): string
    {
        return $this->resolveByExt($name)['icon'] ?? 'bi-file-earmark';
    }

    /**
     * Color de ícono por nombre de archivo.
     */
    public function iconColorByName(?string $name): string
    {
        return $this->resolveByExt($name)['color'] ?? '#aaa';
    }

    /**
     * Reglas serializadas para consumo cliente (sgi-document-uploader.js).
     * Se inyectan vía `<script type="application/json" id="sgi-doc-icon-rules">`
     * en el layout. JSON_HEX_TAG previene cierre prematuro del script.
     */
    public function rulesJson(): string
    {
        return json_encode(
            ['mime' => self::MIME_RULES, 'ext' => self::EXT_RULES],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE,
        );
    }
}
