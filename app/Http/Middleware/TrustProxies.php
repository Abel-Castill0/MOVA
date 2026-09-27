<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * TRUST BOUNDARY (Azure Container Apps forwarded headers, confirmed live):
 * a request to the Azure-generated FQDN carrying a client-supplied
 * `X-Forwarded-Host: www.staging.movaeduca.me` produced a real 301 from
 * RedirectWwwToApex — proof that ACA passes that header through
 * unsanitized, and that trusting it (as `'*'` + HEADER_X_FORWARDED_HOST
 * did) let an external, unauthenticated client control
 * `Request::getHost()`, which every host-based decision in the app reads
 * (RedirectWwwToApex, PreventIndexingWhenDisabled's canonical-host check,
 * any future absolute-URL generation).
 *
 * `'*'` also meant Symfony treated EVERY hop in an X-Forwarded-For chain
 * as trusted, not only the one actually connected to this container — a
 * client can prepend arbitrary values before Azure appends its own.
 *
 * Fixed trust model, deliberately narrow:
 *   - `$proxies = ['REMOTE_ADDR']`: trust only the proxy directly
 *     connected to this container (Symfony resolves this per-request to
 *     the socket peer, i.e. Azure's own ingress — never a client-supplied
 *     value), not every arbitrary IP a client claims forwarded the
 *     request.
 *   - X-Forwarded-For: ACA's documented behavior is to APPEND its own
 *     value to whatever the client sent — trusting it, combined with only
 *     the immediate peer being a trusted proxy, means Symfony reads the
 *     rightmost (Azure-appended) entry as the real client IP, which is
 *     exactly what MOVA's rate limiters key on (see the "RATE LIMITERS"
 *     note in TrustProxiesTest).
 *   - X-Forwarded-Proto: ACA overwrites this to the real external scheme
 *     (http vs https) before the request reaches the container — needed
 *     so `$request->isSecure()`/`URL::forceScheme('https')` see the truth
 *     even though the container itself is reached over plain HTTP inside
 *     Azure's network.
 *   - X-Forwarded-Host is deliberately ABSENT from `$headers`: Host must
 *     come from the real `Host`/`:authority` value only, never a header
 *     any client can set. Azure does not document XFH as a trust signal
 *     for ACA, and the live probe proved it isn't sanitized — MOVA must
 *     not trust it regardless.
 *   - X-Forwarded-Port and X-Forwarded-Aws-Elb are dropped: MOVA doesn't
 *     run behind an AWS ELB, and the port ACA presents to the container
 *     is not authoritative for anything this app decides from the
 *     request.
 *
 * TrustHosts stays off (see Kernel.php) — unrelated to this fix, that's a
 * separate, deliberate decision made in an earlier gate.
 */
class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application — ONLY the proxy directly
     * connected to this container (resolved from the socket peer, not a
     * client-supplied header), never every host ('*').
     *
     * @var array<int, string>|string|null
     */
    protected $proxies = [
        'REMOTE_ADDR',
    ];

    /**
     * The headers that should be used to detect proxies — deliberately
     * excludes HEADER_X_FORWARDED_HOST (see class docblock: Azure does not
     * sanitize it, confirmed live), HEADER_X_FORWARDED_PORT and
     * HEADER_X_FORWARDED_AWS_ELB.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_PROTO;
}
