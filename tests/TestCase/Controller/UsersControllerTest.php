<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\Factory\UserFactory;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class UsersControllerTest extends TestCase
{
    use IntegrationTestTrait;

    private const PASSWORD = 'Secreta123!';

    protected function setUp(): void
    {
        parent::setUp();
        TableRegistry::getTableLocator()->get('RateLimitBuckets')->deleteAll('1=1');
        UserFactory::new([
            'username' => 'aprobador',
            'password' => self::PASSWORD,
            'active' => true,
        ])->save();
        $this->enableCsrfToken();
        $this->enableRetainFlashMessages();
    }

    private function accountBucketCount(string $username): int
    {
        $windowStart = (int)floor(time() / 900) * 900;
        $key = hash('sha256', 'login_user|' . strtolower(trim($username)) . '|' . $windowStart);

        return TableRegistry::getTableLocator()->get('RateLimitBuckets')->getCount($key);
    }

    public function testLoginToleratesSeveralRequestsUnderEdgeLimit(): void
    {
        // Con el límite viejo (5/300) el 6º GET daría 429; con 30/300 pasa.
        for ($i = 0; $i < 6; $i++) {
            $this->get('/login');
            $this->assertResponseOk();
        }
    }

    public function testGetRendersLoginForm(): void
    {
        $this->get('/login');
        $this->assertResponseOk();
        $this->assertResponseContains('name="username"');
    }

    public function testWrongCredentialsShowFlashMessage(): void
    {
        $this->post('/login', ['username' => 'aprobador', 'password' => 'mala']);
        $this->assertResponseOk();
        $this->assertResponseContains('Usuario o contraseña incorrectos.');
    }

    public function testCorrectCredentialsRedirectAndClearCounter(): void
    {
        // Tres fallos previos: el éxito debe limpiar el contador.
        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', ['username' => 'aprobador', 'password' => 'mala']);
        }
        $this->assertSame(3, $this->accountBucketCount('aprobador'));

        $this->post('/login', ['username' => 'aprobador', 'password' => self::PASSWORD]);
        $this->assertResponseCode(302);
        $this->assertSame(0, $this->accountBucketCount('aprobador'));
    }

    public function testAccountLockoutDeniesEvenCorrectPassword(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/login', ['username' => 'aprobador', 'password' => 'mala']);
        }
        // Cuenta bloqueada: incluso la contraseña correcta se deniega (oráculo cerrado).
        $this->post('/login', ['username' => 'aprobador', 'password' => self::PASSWORD]);
        $this->assertResponseOk();
        $this->assertResponseContains('Demasiados intentos fallidos');
    }

    public function testLoginFlashIsWrappedForVisibility(): void
    {
        $this->post('/login', ['username' => 'aprobador', 'password' => 'mala']);
        $this->assertResponseOk();
        // El toast debe estar dentro del contenedor que lo hace visible (vence a
        // .toast:not(.show){display:none} de Bootstrap) y la página carga el JS.
        $this->assertResponseContains('id="spi-flash-container"');
        $this->assertResponseContains('spi-common');
    }

    public function testLockedOutSuccessfulLoginLeavesSessionUnauthenticated(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/login', ['username' => 'aprobador', 'password' => 'mala']);
        }
        // Intento con la contraseña correcta pero la cuenta ya bloqueada: el
        // logout() defensivo revierte la sesión que el middleware persistió.
        $this->post('/login', ['username' => 'aprobador', 'password' => self::PASSWORD]);
        $this->assertResponseOk();

        // Garantía de seguridad: la sesión NO quedó autenticada; una ruta
        // protegida redirige a /login.
        $this->get('/');
        $this->assertRedirectContains('/login');
    }

    public function testLockedOutFailedAttemptShowsSameBlockMessage(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/login', ['username' => 'aprobador', 'password' => 'mala']);
        }
        // Un intento fallido tras el bloqueo muestra el mensaje de bloqueo, no el
        // genérico: mismo texto para éxito y fallo (oráculo cerrado).
        $this->post('/login', ['username' => 'aprobador', 'password' => 'otra-mala']);
        $this->assertResponseOk();
        $this->assertResponseContains('Demasiados intentos fallidos');
        $this->assertResponseNotContains('Usuario o contraseña incorrectos.');
    }
}
