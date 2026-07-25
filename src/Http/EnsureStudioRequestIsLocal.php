<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Http;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RaceProof\Laravel\Support\ConfigValue;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * @internal Studio trusts the direct peer address, never forwarded headers.
 */
final readonly class EnsureStudioRequestIsLocal
{
    public function __construct(private Config $config) {}

    /** @param Closure(Request): SymfonyResponse $next */
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $remoteAddress = $request->server('REMOTE_ADDR');
        $allowed = ConfigValue::stringList($this->config, 'raceproof.studio.allowed_ips');

        if (! is_string($remoteAddress) || ! in_array($remoteAddress, $allowed, true)) {
            return new Response('RaceProof Studio accepts only explicitly allowed direct client addresses.', 403, [
                'Cache-Control' => 'no-store, private',
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
                'Pragma' => 'no-cache',
                'Referrer-Policy' => 'no-referrer',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'DENY',
            ]);
        }

        return $next($request);
    }
}
