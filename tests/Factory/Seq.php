<?php
declare(strict_types=1);

namespace App\Test\Factory;

/**
 * Secuencia global incremental para valores únicos en factories.
 *
 * fixture-factories usa un generador Faker con seed determinista por instancia de
 * factory, por lo que dos factories del mismo tipo en un mismo test generan el
 * mismo "primer valor" y colisionan en columnas UNIQUE. Esta secuencia garantiza
 * unicidad absoluta cross-instancia y cross-test (un solo proceso PHPUnit).
 */
final class Seq
{
    private static int $n = 0;

    public static function next(): int
    {
        return ++self::$n;
    }
}
