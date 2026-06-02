<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\DianCrosscheckService;
use App\Service\N8nService;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests para DianCrosscheckService::processUpload() — guardas de validación
 * del archivo subido (MIME y tamaño), que retornan antes de tocar filesystem/BD/n8n.
 *
 * El happy path (moveTo + persistencia + envío a n8n) queda para integración.
 */
final class DianCrosscheckServiceTest extends TestCase
{
    private function service(): DianCrosscheckService
    {
        return new DianCrosscheckService($this->createStub(N8nService::class));
    }

    public function testRejectsNonExcelMime(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('getClientMediaType')->willReturn('application/pdf');

        $result = $this->service()->processUpload($file, 9);

        $this->assertFalse($result->success);
        $this->assertSame(['El archivo debe ser un archivo Excel (.xls o .xlsx).'], $result->errors);
    }

    public function testRejectsOversizedFile(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('getClientMediaType')
            ->willReturn('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $file->method('getSize')->willReturn(11 * 1024 * 1024); // > 10 MB

        $result = $this->service()->processUpload($file, 9);

        $this->assertFalse($result->success);
        $this->assertSame(['El archivo no debe superar los 10 MB.'], $result->errors);
    }
}
