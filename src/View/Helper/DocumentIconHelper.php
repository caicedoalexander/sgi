<?php
declare(strict_types=1);

namespace App\View\Helper;

use Cake\View\Helper;

class DocumentIconHelper extends Helper
{
    /**
     * Reglas MIME → ícono/color. Fuente única para PHP y para el cliente JS
     * (vía `rulesJson()` que se inyecta como `<script type="application/json"
     * id="spi-doc-icon-rules">` en el layout — ver audit CR-202).
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

    /**
     * Resuelve la regla MIME → ícono/color/label; la primera coincidencia gana y
     * la entrada con `matches` vacío actúa como default.
     *
     * @param string|null $mime Tipo MIME del documento.
     * @return array Regla con claves matches/icon/color/label.
     */
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

    /**
     * @param string|null $mime Tipo MIME del documento.
     * @return string Clase Bootstrap Icons del ícono.
     */
    public function iconClass(?string $mime): string
    {
        return $this->resolveByMime($mime)['icon'];
    }

    /**
     * @param string|null $mime Tipo MIME del documento.
     * @return string Color del ícono (hex o var() CSS).
     */
    public function iconColor(?string $mime): string
    {
        return $this->resolveByMime($mime)['color'];
    }

    /**
     * @param string|null $mime Tipo MIME del documento.
     * @return string Etiqueta corta de tipo para el badge.
     */
    public function typeLabel(?string $mime): string
    {
        return $this->resolveByMime($mime)['label'];
    }

    /**
     * @param string $type Etiqueta de tipo (PDF, JPG, WORD, EXCEL, …).
     * @return string Clase de pill del badge según el tipo.
     */
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

    /**
     * Resuelve la regla ícono/color por la extensión final del archivo, o null si
     * no hay extensión reconocida.
     *
     * @param string|null $name Nombre del archivo.
     * @return array|null Regla con claves exts/icon/color, o null.
     */
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
     * Reglas serializadas para consumo cliente (spi-document-uploader.js).
     * Se inyectan vía `<script type="application/json" id="spi-doc-icon-rules">`
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
