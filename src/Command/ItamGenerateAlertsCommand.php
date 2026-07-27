<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\AssetAlertService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Calcula y persiste las alertas del inventario TI (stock bajo, actas
 * pendientes, activos sin responsable, registros incompletos) y marca las actas
 * vencidas. Pensado para agendarse en el cron del servidor.
 *
 * El push a n8n NO se hace aquí (vive en el plan ITAM posterior).
 */
class ItamGenerateAlertsCommand extends Command
{
    /**
     * Builds the option parser for this command.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser The option parser to configure.
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription('Genera y persiste las alertas del inventario TI.');

        return $parser;
    }

    /**
     * Executes the command to generate and persist asset alerts.
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console IO object.
     * @return int The exit code.
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $io->info('Generando alertas de inventario…');

        $stats = (new AssetAlertService())->generate();

        $io->success(sprintf(
            'Alertas: %d creadas, %d marcadas como vencidas.',
            $stats['created'],
            $stats['overdue'],
        ));

        return self::CODE_SUCCESS;
    }
}
