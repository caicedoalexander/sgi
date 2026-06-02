<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Test\Factory\ProviderFactory;
use Cake\TestSuite\TestCase;

/**
 * Test de integración de ejemplo (con BD) — valida el setup end-to-end de
 * fixture-factories: persistir vía factory, leer vía la Table real y el rollback
 * transaccional entre tests (FactoryTransactionStrategy).
 *
 * No declara $fixtures: la estrategia revierte todos los cambios tras cada test.
 */
final class ProvidersTableIntegrationTest extends TestCase
{
    public function testFactoryPersistsAndTableReads(): void
    {
        $provider = ProviderFactory::new()->save();

        $table = $this->fetchTable('Providers');
        $found = $table->get($provider->id);

        $this->assertSame($provider->name, $found->name);
        $this->assertSame('NIT', $found->document_type);
        $this->assertTrue($found->active);
    }

    public function testRollbackIsolatesTestsAndSaveMany(): void
    {
        $table = $this->fetchTable('Providers');

        // La BD arranca limpia: el rollback revirtió lo del test anterior.
        $this->assertSame(0, $table->find()->count());

        ProviderFactory::new()->count(3)->saveMany();

        $this->assertSame(3, $table->find()->count());
    }

    public function testInactiveStateAndBuildDoesNotPersist(): void
    {
        $entity = ProviderFactory::new()->inactive()->build();

        $this->assertFalse($entity->active);
        // build() no persiste.
        $this->assertSame(0, $this->fetchTable('Providers')->find()->count());
    }
}
