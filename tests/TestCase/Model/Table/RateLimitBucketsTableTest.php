<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\RateLimitBucketsTable;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use DateTime;

class RateLimitBucketsTableTest extends TestCase
{
    private RateLimitBucketsTable $buckets;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var \App\Model\Table\RateLimitBucketsTable $table */
        $table = TableRegistry::getTableLocator()->get('RateLimitBuckets');
        $this->buckets = $table;
        $this->buckets->deleteAll('1=1');
    }

    public function testClearKeyRemovesTheBucketRow(): void
    {
        $windowStart = (int)floor(time() / 900) * 900;
        $this->buckets->incrementAndGet('k-test', $windowStart);
        $this->assertSame(1, $this->buckets->getCount('k-test'));

        $removed = $this->buckets->clearKey('k-test');

        $this->assertSame(1, $removed);
        $this->assertSame(0, $this->buckets->getCount('k-test'));
    }

    public function testGarbageCollectKeepsBucketsYoungerThanRetentionFloor(): void
    {
        // Bucket cuya ventana empezó hace 10 min (600 s): dentro del piso de 1 h.
        $tenMinAgo = (new DateTime())->modify('-600 seconds')->format('Y-m-d H:i:s');
        $this->buckets->getConnection()->execute(
            'INSERT INTO rate_limit_buckets (bucket_key, window_start, count, created, modified)
             VALUES (?, ?, 1, ?, ?)',
            ['k-recent', $tenMinAgo, $tenMinAgo, $tenMinAgo],
        );

        // Un limitador de ventana corta pide GC de 300 s; el piso lo eleva a 3600 s.
        $this->buckets->garbageCollect(300);

        $this->assertSame(1, $this->buckets->getCount('k-recent'));
    }
}
