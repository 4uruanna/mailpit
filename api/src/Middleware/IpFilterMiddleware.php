<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Http\ClientIpResolver;
use App\Http\IpMatcher;
use App\Http\Json;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Refuse (403) toute requête qui ne vient pas d'une IP autorisée.
 * L'IP du client est transmise aux actions via l'attribut « client_ip ».
 */
final readonly class IpFilterMiddleware implements MiddlewareInterface
{
    public const string ATTRIBUTE = 'client_ip';

    public function __construct(
        private ClientIpResolver $resolver,
        private IpMatcher $allowed,
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = $this->resolver->resolve($request);

        if (!$this->allowed->matches($ip)) {
            // Le log indique l'IP vue par l'API : pratique pour remplir allowed_ips.
            error_log(sprintf(
                '[ip-filter] requête refusée depuis « %s » : %s %s',
                $ip,
                $request->getMethod(),
                $request->getUri()->getPath(),
            ));

            return Json::response($this->responseFactory, 403, ['error' => 'Forbidden']);
        }

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $ip));
    }
}
