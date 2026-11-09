<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Auth\ApiScope;
use App\Http\ClientIpResolver;
use App\Http\IpMatcher;
use App\Http\Json;
use App\Persistence\TokenRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Authentifie un jeton développeur (« Authorization: Bearer mpt_… », créé dans le Dashboard)
 * et transmet aux actions ce qu'il a le droit de voir (attribut ApiScope::ATTRIBUTE).
 *
 * Si $relayToken est fourni, le jeton du relais est aussi accepté, avec accès à tous les mails,
 * mais seulement depuis les IP autorisées (webhook.allowed_ips) : sinon 403.
 *
 * 401 si le jeton est absent ou inconnu.
 */
final readonly class ApiTokenMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TokenRepository $tokens,
        private ResponseFactoryInterface $responseFactory,
        private ?string $relayToken = null,
        private ?ClientIpResolver $ipResolver = null,
        private ?IpMatcher $relayIps = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $given = TokenAuthMiddleware::bearer($request);

        if ($given !== null && $this->relayToken !== null && hash_equals($this->relayToken, $given)) {
            $ip = $this->ipResolver?->resolve($request) ?? '';
            if ($this->relayIps === null || !$this->relayIps->matches($ip)) {
                error_log(sprintf('[api-auth] jeton du relais refusé depuis « %s » : %s %s', $ip, $request->getMethod(), $request->getUri()->getPath()));

                return Json::response($this->responseFactory, 403, ['error' => 'Forbidden']);
            }

            return $handler->handle($request->withAttribute(ApiScope::ATTRIBUTE, ApiScope::relay()));
        }

        $token = $given === null ? null : $this->tokens->authenticate($given);
        if ($token === null) {
            error_log(sprintf(
                '[api-auth] jeton %s : %s %s',
                $given === null ? 'absent' : 'invalide',
                $request->getMethod(),
                $request->getUri()->getPath(),
            ));

            return Json::response($this->responseFactory, 401, ['error' => 'Unauthorized'])
                ->withHeader('WWW-Authenticate', 'Bearer');
        }

        $scope = ApiScope::token($token['id'], $this->tokens->addresses($token['id']));

        return $handler->handle($request->withAttribute(ApiScope::ATTRIBUTE, $scope));
    }
}
