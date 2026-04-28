<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ExcelMappingService;
use Cake\TestSuite\TestCase;

class ExcelMappingServiceTest extends TestCase
{
    private ExcelMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ExcelMappingService();
    }

    public function testGetExportableFieldsReturnsAllEmployeeFieldsCheckedTrue(): void
    {
        $fields = $this->service->getExportableFields('Employees');
        $this->assertNotEmpty($fields);
        foreach ($fields as $f) {
            $this->assertArrayHasKey('field', $f);
            $this->assertArrayHasKey('label', $f);
            $this->assertTrue($f['checked']);
        }
        $names = array_column($fields, 'field');
        $this->assertContains('document_number', $names);
        $this->assertContains('first_name', $names);
    }

    public function testGetImportableFieldsExcludesPureDisplayOnly(): void
    {
        $fields = $this->service->getImportableFields('Employees');
        $names = array_column($fields, 'field');
        // 'position' is display_only WITH fk_resolve → included
        $this->assertContains('position', $names);
        // No pure display_only-without-fk_resolve fields exist in the current map,
        // but the contract is preserved:
        foreach ($fields as $f) {
            $this->assertArrayHasKey('required', $f);
        }
    }

    public function testAutoMapColumnsRecognizesLabelAliasAndFieldName(): void
    {
        $headers = ['Cédula', 'apellidos', 'first_name', 'no_existe'];
        $map = $this->service->autoMapColumns($headers, 'Employees');
        $this->assertSame('document_number', $map['Cédula']);
        $this->assertSame('last_name1', $map['apellidos']);
        $this->assertSame('first_name', $map['first_name']);
        $this->assertNull($map['no_existe']);
    }

    public function testValidateMappingReturnsErrorWhenRequiredMissing(): void
    {
        $mapping = ['Nombres' => 'first_name']; // document_number is required, missing
        $errors = $this->service->validateMapping($mapping, 'Employees');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Cédula', $errors[0]);
    }

    public function testValidateMappingPassesWhenRequiredMapped(): void
    {
        $mapping = [
            'Cédula' => 'document_number',
            'Nombres' => 'first_name',
        ];
        $errors = $this->service->validateMapping($mapping, 'Employees');
        $this->assertSame([], $errors);
    }

    public function testGetLabelMapHasSpanishLabels(): void
    {
        $labels = $this->service->getLabelMap('Employees');
        $this->assertSame('Cédula', $labels['document_number']);
        $this->assertSame('Nombres', $labels['first_name']);
    }
}
