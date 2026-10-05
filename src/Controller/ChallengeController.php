<?php declare(strict_types=1);

namespace Mxcoan\Controller;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public challenge endpoint for the MetriXs site verification handshake.
 *
 * Contract (mirrors the WordPress/Grav/Craft/PrestaShop plugins):
 *   1. Plugin → API  POST /api/integrations/shopware/challenge (Bearer key)
 *      API stores a fresh random token in sites.verification_token and
 *      returns it.
 *   2. Plugin saves the token in SystemConfig; this controller serves it at
 *      GET https://<domain>/mxcoan/challenge.
 *   3. Plugin → API  POST /api/integrations/shopware/verify (Bearer key)
 *      API fetches this URL and compares (constant-time) with the stored
 *      token. Match → site.verified = true. Only someone who controls the
 *      domain can make this endpoint return the token.
 *
 * The token is cleared by ConnectService once the handshake completes.
 *
 * Storefront scope, no HTTP cache: the route is NOT marked _httpCache, so
 * Shopware never caches it (fresh token on every fetch).
 */
class ChallengeController extends StorefrontController
{
    public const CONFIG_PREFIX = 'Mxcoan.config.';

    private SystemConfigService $systemConfig;

    public function __construct(SystemConfigService $systemConfig)
    {
        $this->systemConfig = $systemConfig;
    }

    #[Route(
        path: '/mxcoan/challenge',
        name: 'frontend.mxcoan.challenge',
        methods: ['GET'],
        defaults: ['_routeScope' => ['storefront']]
    )]
    public function challenge(): JsonResponse
    {
        $challenge = $this->systemConfig->getString(self::CONFIG_PREFIX . 'challengeToken');
        if ($challenge === '' || $challenge === null) {
            return new JsonResponse(['error' => 'no_challenge'], 404);
        }

        return new JsonResponse(['challenge' => $challenge]);
    }
}