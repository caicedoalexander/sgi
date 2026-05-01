<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use DateTime;

class RateLimitBucketsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('rate_limit_buckets');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
    }

    /**
     * Atomically increment the counter for the given bucket key in the
     * given window, returning the resulting count.
     */
    public function incrementAndGet(string $bucketKey, int $windowStart): int
    {
        $connection = $this->getConnection();
        $now = (new DateTime())->format('Y-m-d H:i:s');
        $windowDt = (new DateTime("@{$windowStart}"))->format('Y-m-d H:i:s');

        $connection->execute(
            'INSERT INTO rate_limit_buckets (bucket_key, window_start, count, created, modified)
             VALUES (?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE count = count + 1, modified = ?',
            [$bucketKey, $windowDt, $now, $now, $now],
        );

        $stmt = $connection->execute(
            'SELECT count FROM rate_limit_buckets WHERE bucket_key = ?',
            [$bucketKey],
        );

        return (int)$stmt->fetchColumn(0);
    }

    /**
     * Delete bucket rows whose window started more than $olderThanSeconds ago.
     */
    public function garbageCollect(int $olderThanSeconds): int
    {
        $cutoff = (new DateTime())->modify("-{$olderThanSeconds} seconds")->format('Y-m-d H:i:s');

        return $this->deleteAll(['window_start <' => $cutoff]);
    }
}
