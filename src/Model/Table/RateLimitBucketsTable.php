<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use DateTime;

class RateLimitBucketsTable extends Table
{
    /**
     * Piso de retención de la GC. Los buckets de limitadores con ventanas más
     * largas (p. ej. el throttle de login, 900 s) deben sobrevivir toda su
     * ventana aunque un limitador de ventana corta dispare la GC.
     */
    private const MIN_RETENTION_SECONDS = 3600;

    /**
     * Initialize method
     *
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
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
     * Return the current count for a bucket key without incrementing it.
     */
    public function getCount(string $bucketKey): int
    {
        $stmt = $this->getConnection()->execute(
            'SELECT count FROM rate_limit_buckets WHERE bucket_key = ?',
            [$bucketKey],
        );

        return (int)$stmt->fetchColumn(0);
    }

    /**
     * Delete bucket rows whose window started more than $olderThanSeconds ago,
     * never borrando buckets más recientes que MIN_RETENTION_SECONDS.
     */
    public function garbageCollect(int $olderThanSeconds): int
    {
        $effective = max($olderThanSeconds, self::MIN_RETENTION_SECONDS);
        $cutoff = (new DateTime())->modify("-{$effective} seconds")->format('Y-m-d H:i:s');

        return $this->deleteAll(['window_start <' => $cutoff]);
    }

    /**
     * Delete the single bucket row for the given key. Usado por el throttle de
     * login para resetear el contador de una cuenta tras un login exitoso.
     */
    public function clearKey(string $bucketKey): int
    {
        return $this->deleteAll(['bucket_key' => $bucketKey]);
    }
}
