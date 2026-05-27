<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ImportResult;
use PHPUnit\Framework\TestCase;

final class ImportResultTest extends TestCase
{
    public function testInitialStateIsAllZeros(): void
    {
        $r = new ImportResult();
        $this->assertSame(0, $r->created);
        $this->assertSame(0, $r->updated);
        $this->assertSame(0, $r->unchanged);
        $this->assertSame(0, $r->skipped);
        $this->assertSame([], $r->errors);
    }

    public function testSummaryEmptyWhenNothingHappened(): void
    {
        $this->assertSame('Sin cambios', (new ImportResult())->getSummary());
    }

    public function testSummaryShowsCounts(): void
    {
        $r = new ImportResult();
        $r->created = 2;
        $r->updated = 3;
        $r->unchanged = 1;
        $r->skipped = 4;
        $r->errors = ['x', 'y'];

        $this->assertSame('2 creados, 3 actualizados, 1 sin cambios, 4 omitidos, 2 errores', $r->getSummary());
    }

    public function testSummarySkipsZeroBuckets(): void
    {
        $r = new ImportResult();
        $r->created = 5;
        $this->assertSame('5 creados', $r->getSummary());

        $r2 = new ImportResult();
        $r2->errors = ['solo error'];
        $this->assertSame('1 errores', $r2->getSummary());
    }
}
