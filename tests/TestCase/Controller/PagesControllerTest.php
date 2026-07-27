<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Verifica que SGI está protegido por autenticación: tanto la raíz del dashboard
 * como la ruta vestigial /pages/* del skeleton redirigen a /login cuando el usuario
 * no está autenticado.
 *
 * Sustituye los tests heredados del skeleton de CakePHP, que asumían acceso anónimo
 * y aseveraban contenido de la home por defecto ('CakePHP', '<html>') e internals del
 * error renderer ('stack-frames', 'Missing Template') — todos rotos bajo la auth de
 * SGI (AuthenticationMiddleware redirige 302 a /login antes de llegar al controller).
 *
 * NOTA: los vectores de seguridad bajo sesión autenticada (directory traversal → 403,
 * CSRF en POST → 403) requieren una sesión logueada y pertenecen a un test de
 * integración dedicado con usuario sembrado; quedan fuera de este guard de auth.
 */
class PagesControllerTest extends TestCase
{
    use IntegrationTestTrait;

    public function testUnauthenticatedAccessToPagesRedirectsToLogin(): void
    {
        $this->get('/pages/home');

        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testUnauthenticatedAccessToDashboardRootRedirectsToLogin(): void
    {
        $this->get('/');

        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }
}
