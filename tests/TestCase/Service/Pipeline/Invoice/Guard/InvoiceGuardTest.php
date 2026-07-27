<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Pipeline\Invoice\Guard;

use App\Service\Pipeline\Invoice\Guard\InvoiceGuard;
use App\Test\Factory\InvoiceDocumentFactory;
use App\Test\Factory\InvoiceFactory;
use Cake\TestSuite\TestCase;

final class InvoiceGuardTest extends TestCase
{
    public function testHasAnyDocumentFalseWithoutDocs(): void
    {
        $invoice = InvoiceFactory::new()->save();
        $this->assertFalse((new InvoiceGuard())->hasAnyDocument((int)$invoice->id));
    }

    public function testHasAnyDocumentTrueWithAnyDoc(): void
    {
        $invoice = InvoiceFactory::new()->save();
        InvoiceDocumentFactory::new(['invoice_id' => $invoice->id])->save();
        $this->assertTrue((new InvoiceGuard())->hasAnyDocument((int)$invoice->id));
    }
}
