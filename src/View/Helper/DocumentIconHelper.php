<?php
declare(strict_types=1);

namespace App\View\Helper;

use Cake\View\Helper;

class DocumentIconHelper extends Helper
{
    /**
     * @param string|null $mime
     * @return string
     */
    public function iconClass(?string $mime): string
    {
        return match (true) {
            str_contains($mime ?? '', 'pdf') => 'bi-file-earmark-pdf',
            str_contains($mime ?? '', 'image') => 'bi-file-earmark-image',
            str_contains($mime ?? '', 'wordprocessingml')
                || str_contains($mime ?? '', 'msword') => 'bi-file-earmark-word',
            str_contains($mime ?? '', 'spreadsheet')
                || str_contains($mime ?? '', 'excel') => 'bi-file-earmark-excel',
            str_contains($mime ?? '', 'text/plain') => 'bi-file-earmark-text',
            default => 'bi-file-earmark',
        };
    }

    /**
     * @param string|null $mime
     * @return string
     */
    public function iconColor(?string $mime): string
    {
        return match (true) {
            str_contains($mime ?? '', 'pdf') => '#dc3545',
            str_contains($mime ?? '', 'image') => '#0dcaf0',
            str_contains($mime ?? '', 'wordprocessingml')
                || str_contains($mime ?? '', 'msword') => '#0d6efd',
            str_contains($mime ?? '', 'spreadsheet')
                || str_contains($mime ?? '', 'excel') => 'var(--primary-color)',
            default => '#aaa',
        };
    }

    /**
     * @param string|null $mime
     * @return string
     */
    public function typeLabel(?string $mime): string
    {
        return match (true) {
            str_contains($mime ?? '', 'pdf') => 'PDF',
            str_contains($mime ?? '', 'image/jpeg')
                || str_contains($mime ?? '', 'image/jpg') => 'JPG',
            str_contains($mime ?? '', 'image/png') => 'PNG',
            str_contains($mime ?? '', 'image') => 'IMG',
            str_contains($mime ?? '', 'wordprocessingml')
                || str_contains($mime ?? '', 'msword') => 'WORD',
            str_contains($mime ?? '', 'spreadsheet')
                || str_contains($mime ?? '', 'excel') => 'EXCEL',
            str_contains($mime ?? '', 'text/plain') => 'TXT',
            default => 'ARCH.',
        };
    }

    /**
     * @param string $type
     * @return string
     */
    public function badgeClass(string $type): string
    {
        return match ($type) {
            'PDF' => 'badge-outline-danger',
            'JPG', 'PNG', 'IMG' => 'badge-outline-info',
            'WORD' => 'badge-outline-dark',
            'EXCEL' => 'badge-outline-success',
            default => 'badge-outline-dark',
        };
    }

    /**
     * Resuelve clase de ícono por nombre de archivo (cuando no se tiene el MIME).
     */
    public function iconClassByName(?string $name): string
    {
        $name = strtolower($name ?? '');

        return match (true) {
            str_ends_with($name, '.pdf') => 'bi-file-earmark-pdf',
            (bool)preg_match('/\.(jpg|jpeg|png|gif|webp)$/', $name) => 'bi-file-earmark-image',
            (bool)preg_match('/\.(doc|docx)$/', $name) => 'bi-file-earmark-word',
            (bool)preg_match('/\.(xls|xlsx|csv)$/', $name) => 'bi-file-earmark-excel',
            str_ends_with($name, '.txt') => 'bi-file-earmark-text',
            default => 'bi-file-earmark',
        };
    }

    /**
     * Color de ícono por nombre de archivo.
     */
    public function iconColorByName(?string $name): string
    {
        $name = strtolower($name ?? '');

        return match (true) {
            str_ends_with($name, '.pdf') => '#dc3545',
            (bool)preg_match('/\.(jpg|jpeg|png|gif|webp)$/', $name) => '#0dcaf0',
            (bool)preg_match('/\.(doc|docx)$/', $name) => '#0d6efd',
            (bool)preg_match('/\.(xls|xlsx|csv)$/', $name) => 'var(--primary-color)',
            default => '#aaa',
        };
    }
}
