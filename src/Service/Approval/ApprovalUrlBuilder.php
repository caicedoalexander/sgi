<?php
declare(strict_types=1);

namespace App\Service\Approval;

use Cake\Http\ServerRequest;

/**
 * Construye las URLs públicas de aprobación externa (`{base}/approve/{token}`).
 *
 * Centraliza el cálculo del scheme respetando `X-Forwarded-Proto` (terminación
 * TLS en un proxy) para que el token-secreto no viaje en un enlace `http://`.
 * Antes este cálculo estaba duplicado en tres sitios con dos variantes: una
 * respetaba el header (facturas) y dos no (novedades add/resend).
 */
final class ApprovalUrlBuilder
{
    public const APPROVE_PATH = '/approve/';

    /**
     * Base URL (`scheme://host`) respetando `X-Forwarded-Proto`.
     */
    public static function baseFromRequest(ServerRequest $request): string
    {
        $scheme = $request->getHeaderLine('X-Forwarded-Proto') ?: $request->scheme();

        return $scheme . '://' . $request->host();
    }

    /**
     * URL de aprobación a partir de un base ya calculado.
     */
    public static function approveUrl(string $baseUrl, string $token): string
    {
        return $baseUrl . self::APPROVE_PATH . $token;
    }

    /**
     * URL de aprobación directamente desde el request entrante.
     */
    public static function fromRequest(ServerRequest $request, string $token): string
    {
        return self::approveUrl(self::baseFromRequest($request), $token);
    }
}
