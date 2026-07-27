<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Convierte la base de datos completa de latin1 a utf8mb4.
 *
 * La conexión de la app ya declara `encoding => utf8mb4` (config/app.php), pero el
 * esquema estaba en latin1_swedish_ci, así que MySQL convertía utf8mb4 → latin1 en
 * cada escritura y abortaba con el error 1366 ante cualquier carácter inexistente en
 * latin1: acentos combinantes NFD (nombres de archivo generados en macOS/iOS),
 * emojis, y en general todo lo que esté fuera de cp1252.
 *
 * Los datos existentes estaban correctamente almacenados como latin1 (sin doble
 * encoding), por lo que `CONVERT TO CHARACTER SET` los reinterpreta sin pérdida.
 */
class ConvertDatabaseToUtf8mb4 extends BaseMigration
{
    private const CHARSET = 'utf8mb4';
    private const COLLATION = 'utf8mb4_unicode_ci';

    /**
     * Tablas de control del propio Migrations: se excluyen porque se escriben
     * durante esta misma migración y sólo guardan identificadores ASCII.
     */
    private const EXCLUDED_TABLES = ['cake_migrations', 'cake_seeds', 'phinxlog'];

    /**
     * @return void
     */
    public function up(): void
    {
        $this->convertTo(self::CHARSET, self::COLLATION);
    }

    /**
     * Revertir a latin1 es una operación con pérdida: cualquier carácter guardado
     * tras la conversión que no exista en latin1 (emojis, acentos combinantes) se
     * degrada o aborta el ALTER. Ante un rollback real, restaurar desde backup.
     */
    public function down(): void
    {
        $this->convertTo('latin1', 'latin1_swedish_ci');
    }

    /**
     * @param string $charset Charset destino.
     * @param string $collation Collation destino.
     * @return void
     */
    private function convertTo(string $charset, string $collation): void
    {
        $database = $this->fetchAll('SELECT DATABASE() AS db')[0]['db'];

        $this->execute(sprintf(
            'ALTER DATABASE `%s` CHARACTER SET %s COLLATE %s',
            $database,
            $charset,
            $collation,
        ));

        foreach ($this->tablesToConvert($charset) as $table) {
            $this->execute(sprintf(
                'ALTER TABLE `%s` CONVERT TO CHARACTER SET %s COLLATE %s',
                $table,
                $charset,
                $collation,
            ));
        }
    }

    /**
     * @return list<string> Tablas base cuyo collation aún no corresponde al charset destino.
     */
    private function tablesToConvert(string $charset): array
    {
        $rows = $this->fetchAll(sprintf(
            "SELECT TABLE_NAME AS name
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_TYPE = 'BASE TABLE'
               AND TABLE_COLLATION NOT LIKE '%s%%'
               AND TABLE_NAME NOT IN ('%s')
             ORDER BY TABLE_NAME",
            $charset,
            implode("','", self::EXCLUDED_TABLES),
        ));

        return array_map(fn(array $row): string => $row['name'], $rows);
    }
}
