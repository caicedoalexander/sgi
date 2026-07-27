<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\EmployeeDocumentService;
use App\Test\Factory\EmployeeFactory;
use Cake\TestSuite\TestCase;
use Laminas\Diactoros\UploadedFile;

final class EmployeeDocumentServiceTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    private array $createdPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->createdPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    private function service(): EmployeeDocumentService
    {
        return new EmployeeDocumentService();
    }

    private function makePdfUpload(string $name = 'doc.pdf'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'empdoc');
        file_put_contents($tmp, "%PDF-1.4\n%minimal\n");

        return new UploadedFile($tmp, filesize($tmp), UPLOAD_ERR_OK, $name, 'application/pdf');
    }

    private function makeSpoofUpload(string $name = 'fake.pdf'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'empspoof');
        // Contenido binario: finfo lo detecta como application/octet-stream, que no
        // está en la whitelist de MIME de EmployeeDocumentService (a diferencia de
        // texto plano, que sí es un MIME de documento válido en este servicio).
        file_put_contents($tmp, random_bytes(64));

        return new UploadedFile($tmp, filesize($tmp), UPLOAD_ERR_OK, $name, 'application/pdf');
    }

    private function makeNoFileUpload(): UploadedFile
    {
        return new UploadedFile('', 0, UPLOAD_ERR_NO_FILE, '', '');
    }

    public function testUploadDocumentsAllValid(): void
    {
        $employee = EmployeeFactory::new()->save();
        $service = $this->service();
        $folder = $service->createFolder($employee->id, 'Contratos', null)->data;

        $result = $service->uploadDocuments(
            $employee->id,
            $folder->id,
            [$this->makePdfUpload('a.pdf'), $this->makePdfUpload('b.pdf')],
            null,
        );

        $this->assertTrue($result->success);
        $this->assertSame(2, $result->data['uploaded']);
        $this->assertSame([], $result->data['failed']);

        $rows = $this->fetchTable('EmployeeDocuments')
            ->find()->where(['employee_folder_id' => $folder->id])->all()->toArray();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->createdPaths[] = $service->resolveStoragePath($row->file_path);
        }
    }

    public function testUploadDocumentsKeepsNameWithCombiningAccent(): void
    {
        // Los nombres de archivo generados en macOS/iOS llegan en NFD: "O" seguido
        // de U+0301 (acento combinante) en vez de la "Ó" precompuesta. Con el
        // esquema en latin1 el INSERT abortaba con el error 1366 de MySQL.
        $employee = EmployeeFactory::new()->save();
        $service = $this->service();
        $folder = $service->createFolder($employee->id, 'Contratos', null)->data;
        $name = "TANIA GO\u{0301}MEZ ARENAS- INCAPACIDAD.pdf";

        $result = $service->uploadDocuments(
            $employee->id,
            $folder->id,
            [$this->makePdfUpload($name)],
            null,
        );

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->data['uploaded']);

        $row = $this->fetchTable('EmployeeDocuments')->find()->firstOrFail();
        $this->assertSame($name, $row->name);
        $this->createdPaths[] = $service->resolveStoragePath($row->file_path);
    }

    public function testUploadDocumentsMixedReportsFailed(): void
    {
        $employee = EmployeeFactory::new()->save();
        $service = $this->service();
        $folder = $service->createFolder($employee->id, 'Contratos', null)->data;

        $result = $service->uploadDocuments(
            $employee->id,
            $folder->id,
            [$this->makePdfUpload('ok.pdf'), $this->makeSpoofUpload('mal.pdf')],
            null,
        );

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->data['uploaded']);
        $this->assertCount(1, $result->data['failed']);
        $this->assertSame('mal.pdf', $result->data['failed'][0]['name']);
        $this->assertNotSame('', $result->data['failed'][0]['error']);

        foreach ($this->fetchTable('EmployeeDocuments')->find()->all() as $row) {
            $this->createdPaths[] = $service->resolveStoragePath($row->file_path);
        }
    }

    public function testUploadDocumentsAllInvalidIsStillOkWithZeroUploaded(): void
    {
        $employee = EmployeeFactory::new()->save();
        $service = $this->service();
        $folder = $service->createFolder($employee->id, 'Contratos', null)->data;

        $result = $service->uploadDocuments(
            $employee->id,
            $folder->id,
            [$this->makeSpoofUpload('x.pdf'), $this->makeSpoofUpload('y.pdf')],
            null,
        );

        $this->assertTrue($result->success);
        $this->assertSame(0, $result->data['uploaded']);
        $this->assertCount(2, $result->data['failed']);
        $this->assertCount(0, $this->fetchTable('EmployeeDocuments')->find()->all()->toArray());
    }

    public function testUploadDocumentsFiltersNoFileEntries(): void
    {
        $employee = EmployeeFactory::new()->save();
        $service = $this->service();
        $folder = $service->createFolder($employee->id, 'Contratos', null)->data;

        $result = $service->uploadDocuments(
            $employee->id,
            $folder->id,
            [$this->makeNoFileUpload()],
            null,
        );

        $this->assertFalse($result->success);
        $this->assertSame('No se recibió ningún archivo válido.', $result->firstError());
    }

    public function testUploadDocumentsInvalidFolderFailsBatch(): void
    {
        $employee = EmployeeFactory::new()->save();
        $other = EmployeeFactory::new()->save();
        $service = $this->service();
        $foreignFolder = $service->createFolder($other->id, 'Ajena', null)->data;

        $result = $service->uploadDocuments(
            $employee->id,
            $foreignFolder->id,
            [$this->makePdfUpload('a.pdf')],
            null,
        );

        $this->assertFalse($result->success);
        $this->assertCount(0, $this->fetchTable('EmployeeDocuments')->find()->all()->toArray());
    }

    public function testDeleteDocumentReturnsFolderIdInPayload(): void
    {
        $employee = EmployeeFactory::new()->save();
        $service = $this->service();
        $folder = $service->createFolder($employee->id, 'Contratos', null)->data;
        $service->uploadDocuments($employee->id, $folder->id, [$this->makePdfUpload('a.pdf')], null);
        $document = $this->fetchTable('EmployeeDocuments')->find()->firstOrFail();

        $result = $service->deleteDocument((int)$employee->id, (int)$document->id);

        $this->assertTrue($result->success);
        $this->assertSame((int)$folder->id, $result->data['employee_folder_id']);
        $this->assertCount(0, $this->fetchTable('EmployeeDocuments')->find()->all()->toArray());
    }
}
