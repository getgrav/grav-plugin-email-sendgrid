<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSendgrid\Provider;

/**
 * The outbound calls this plugin makes, behind one seam.
 *
 * Three of them, all to SendGrid's Webhooks API and all behind a button: list
 * the account's event webhooks, create or update one, turn signing on and read
 * the public key back. Nothing here runs while a page is being drawn.
 *
 * The seam exists so the suite can answer for itself. A test that reached
 * api.sendgrid.com would need a real API key, would fail on a train, and would
 * be testing SendGrid rather than this plugin.
 *
 * Deliberately tiny: one method, no redirect policy of its own, no streaming.
 * None of the three calls needs any of that, and every option is another thing
 * to get wrong in the one class that talks to the outside.
 */
interface Http
{
    /**
     * Make a request and read a JSON answer.
     *
     * Never throws. A refused connection, a certificate that did not check out
     * and a body that was not JSON all come back as a status and an error
     * string, because every caller here treats them the same way — it tells the
     * merchant what happened and stops.
     *
     * @param string                    $method  GET, POST or PATCH
     * @param array<array-key, mixed>|null $body JSON request body, or null for none
     * @param array<string, string>     $headers
     * @return array{status: int, body: array<array-key, mixed>|null, error: string}
     */
    public function json(string $method, string $url, ?array $body = null, array $headers = []): array;
}
