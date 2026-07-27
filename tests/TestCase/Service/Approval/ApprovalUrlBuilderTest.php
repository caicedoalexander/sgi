<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Approval;

use App\Service\Approval\ApprovalUrlBuilder;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;

class ApprovalUrlBuilderTest extends TestCase
{
    public function testApproveUrlConcatenatesBaseAndToken(): void
    {
        $url = ApprovalUrlBuilder::approveUrl('https://sgi.example.com', 'abc123');

        $this->assertSame('https://sgi.example.com/approve/abc123', $url);
    }

    public function testBaseFromRequestHonorsForwardedProto(): void
    {
        // Petición HTTP detrás de un proxy que terminó TLS (X-Forwarded-Proto: https).
        $request = new ServerRequest([
            'environment' => ['HTTP_HOST' => 'sgi.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https'],
        ]);

        $this->assertSame('https://sgi.example.com', ApprovalUrlBuilder::baseFromRequest($request));
    }

    public function testBaseFromRequestFallsBackToRequestScheme(): void
    {
        $request = new ServerRequest([
            'environment' => ['HTTP_HOST' => 'sgi.example.com', 'HTTPS' => 'on'],
        ]);

        $this->assertSame('https://sgi.example.com', ApprovalUrlBuilder::baseFromRequest($request));
    }

    public function testFromRequestBuildsFullApprovalUrl(): void
    {
        $request = new ServerRequest([
            'environment' => ['HTTP_HOST' => 'sgi.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https'],
        ]);

        $url = ApprovalUrlBuilder::fromRequest($request, 'tok');

        $this->assertSame('https://sgi.example.com/approve/tok', $url);
    }
}
