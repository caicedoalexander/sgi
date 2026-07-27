<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class AssetsDocumentActionsTest extends TestCase
{
    use IntegrationTestTrait;

    public function testUploadRequiresAuthentication(): void
    {
        $this->enableCsrfToken();
        $this->post('/assets/upload-document/1', []);
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }

    public function testDownloadRequiresAuthentication(): void
    {
        $this->get('/assets/download-document/1');
        $this->assertResponseCode(302);
        $this->assertRedirectContains('/login');
    }
}
