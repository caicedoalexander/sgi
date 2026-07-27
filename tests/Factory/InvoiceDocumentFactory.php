<?php
declare(strict_types=1);

namespace App\Test\Factory;

use App\Constants\InvoiceConstants;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

class InvoiceDocumentFactory extends BaseFactory
{
    protected function getRootTableRegistryName(): string
    {
        return 'InvoiceDocuments';
    }

    public function definition(GeneratorInterface $generator): array
    {
        return [
            'pipeline_status' => InvoiceConstants::STATUS_APROBACION,
            'file_path' => 'storage/invoices/test.pdf',
            'file_name' => 'test.pdf',
            'file_size' => 1024,
            'mime_type' => 'application/pdf',
        ];
    }
}
