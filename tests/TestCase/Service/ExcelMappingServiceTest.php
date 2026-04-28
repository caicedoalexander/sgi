<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ExcelMappingService;
use Cake\TestSuite\TestCase;

class ExcelMappingServiceTest extends TestCase
{
    private ExcelMappingService $service;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $fields;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ExcelMappingService();
        $this->fields = [
            'document_number' => [
                'label' => 'Cédula', 'type' => 'string',
                'required' => true, 'is_key' => true,
                'aliases' => ['empleado'],
            ],
            'first_name' => ['label' => 'Nombres', 'type' => 'string', 'required_new' => true],
            'last_name1' => [
                'label' => 'Primer Apellido', 'type' => 'string', 'required_new' => true,
                'aliases' => ['apellidos'],
            ],
            'position' => [
                'label' => 'Cargo', 'type' => 'string', 'display_only' => true,
                'fk_resolve' => 'name', 'fk_table' => 'Positions', 'fk_target' => 'position_id',
            ],
            'profile_image' => [
                'label' => 'Imagen', 'type' => 'string', 'display_only' => true,
            ],
        ];
    }

    public function testGetExportableFieldsAllCheckedTrue(): void
    {
        $fields = $this->service->getExportableFields($this->fields);
        $this->assertCount(5, $fields);
        foreach ($fields as $f) {
            $this->assertTrue($f['checked']);
        }
    }

    public function testGetImportableFieldsExcludesPureDisplayOnly(): void
    {
        $fields = $this->service->getImportableFields($this->fields);
        $names = array_column($fields, 'field');
        $this->assertContains('position', $names);
        $this->assertNotContains('profile_image', $names);
    }

    public function testAutoMapColumnsRecognizesLabelAliasFieldName(): void
    {
        $headers = ['Cédula', 'apellidos', 'first_name', 'no_existe'];
        $map = $this->service->autoMapColumns($headers, $this->fields);
        $this->assertSame('document_number', $map['Cédula']);
        $this->assertSame('last_name1', $map['apellidos']);
        $this->assertSame('first_name', $map['first_name']);
        $this->assertNull($map['no_existe']);
    }

    public function testValidateMappingDetectsMissingRequired(): void
    {
        $mapping = ['Nombres' => 'first_name'];
        $errors = $this->service->validateMapping($mapping, $this->fields);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Cédula', $errors[0]);
    }

    public function testValidateMappingPassesWhenRequiredMapped(): void
    {
        $mapping = ['Cédula' => 'document_number', 'Nombres' => 'first_name'];
        $errors = $this->service->validateMapping($mapping, $this->fields);
        $this->assertSame([], $errors);
    }

    public function testGetLabelMapReturnsFieldToLabelDict(): void
    {
        $labels = $this->service->getLabelMap($this->fields);
        $this->assertSame('Cédula', $labels['document_number']);
        $this->assertSame('Nombres', $labels['first_name']);
    }
}
