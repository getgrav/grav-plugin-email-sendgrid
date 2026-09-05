<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSendgrid\Provider;

use Grav\Plugin\Email\Providers\DeliveryReports;
use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\Email\Providers\Payload;
use Grav\Plugin\Email\Providers\SendHeader;
use Grav\Plugin\Email\Providers\Verdict;
use Grav\Plugin\Email\Providers\WebhookRequest;

/**
 * SendGrid's Event Webhook, read.
 *
 * Documentation: `twilio.com/docs/sendgrid/for-developers/tracking-events/` —
 * the `event` reference and
 * `getting-started-event-webhook-security-features`. Read 2026-09-04.
 *
 * ## The body is an array, always
 *
 * SendGrid batches. One request carries a JSON *list* of event objects, and it
 * is a list even when there is one event in it. That is the one provider whose
 * payload is not an object, which is why {@see parse()} decodes the body itself
 * and checks `array_is_list()` rather than assuming a map.
 *
 * ## Hard and soft
 *
 * Both arrive as `event: bounce` and are told apart by `type`: `bounce` is the
 * hard one and `blocked` is the soft one. Their own words: a block is a
 * temporary denial and the server may accept the message later.
 *
 * `dropped` is a third thing. SendGrid emits it when it refuses to send at
 * all — the address is on its own suppression list, or bounced before, or
 * reported spam — and it is reported here as the contract's own `dropped`
 * type rather than folded into a bounce, because nothing was ever handed to a
 * receiving server and a store may reasonably treat that differently. A store
 * that suppresses on it, as this add-on's callers do, reads the type; the
 * honest reading either way is that the address is not going to receive mail
 * through this transport, and a store that kept queueing it would send forty
 * thousand messages a month at an address SendGrid never attempts.
 *
 * `deferred` is not a bounce and is skipped: it is SendGrid still trying.
 *
 * ## Correlation
 *
 * `smtp-id` is the `Message-ID` of the message as the originating system wrote
 * it, angle brackets included. A store that mints its own `Message-ID` before a
 * campaign message leaves — which the Grav Email plugin hands to SendGrid over
 * SMTP — gets a direct join on it.
 *
 * Two documented gaps that the send-id fallback exists for:
 *
 * - `sg_message_id` is **absent on delayed bounces**, so a store correlating on
 *   SendGrid's own id would lose exactly the events it most wants.
 * - Custom args **do not attach to bounce events that carry a `Return-Path`**,
 *   which SendGrid lists as a known issue with no workaround.
 *
 * Between them that means neither path is reliable on its own for a bounce, and
 * `smtp-id` is the one that survives both. Custom args are read where they are
 * there — they arrive as top-level keys on the event object rather than
 * nested, so a store that set `unique_args` gets them for free.
 *
 * ## The signature
 *
 * ECDSA over SHA-256. `X-Twilio-Email-Event-Webhook-Timestamp` concatenated
 * with the **raw request body**, verified against the base64 DER public key
 * from the webhook's Security features panel, with the base64 signature from
 * `X-Twilio-Email-Event-Webhook-Signature`.
 *
 * SendGrid's own warning is the design constraint: "we deliver a payload that
 * must be used in its raw bytes form. Transformations from raw bytes to a JSON
 * string may remove characters that were used as part of the generated
 * signature." {@see WebhookRequest::$body} is the bytes as they arrived and is
 * never re-encoded, which is what makes this verifiable at all.
 *
 * The public key is pasted bare and is wrapped in PEM armour here, because that
 * is what the dashboard shows and asking a merchant to add two lines of ASCII
 * around it is asking them to get it wrong.
 */
final class SendGridReports implements DeliveryReports
{
    public const SIGNATURE_HEADER = 'x-twilio-email-event-webhook-signature';
    public const TIMESTAMP_HEADER = 'x-twilio-email-event-webhook-timestamp';

    /** The config key the verification key is kept under, in this plugin's own config. */
    public const KEY = 'public_key';

    /** @var array<string, string> their event names to the contract's */
    public const TYPES = [
        'delivered' => Event::DELIVERED,
        'bounce' => Event::BOUNCED,
        'dropped' => Event::DROPPED,
        'spamreport' => Event::COMPLAINED,
        'open' => Event::OPENED,
        'click' => Event::CLICKED,
    ];

    /** How many events one request may carry before the rest are dropped. */
    public const MAX_EVENTS = 1000;

    /** Nothing before this is a real event. 2000-01-01. */
    public const MOMENT_FLOOR = 946684800;

    public function events(): array
    {
        return array_values(array_unique(array_values(self::TYPES)));
    }

    public function verificationKeys(): array
    {
        return [self::KEY];
    }

    /**
     * The name the Email plugin answers, which is `X-Grav-Send-Id` unless the
     * site says otherwise.
     *
     * SendGrid does not echo message headers in its webhooks at all; what it
     * echoes is custom args, as top-level keys on the event. So a store that
     * wants this path sets a custom arg under this name rather than a header —
     * over SMTP that is the `unique_args` map inside `X-SMTPAPI` — and this is
     * the name it uses.
     */
    public function sendHeader(): string
    {
        return SendHeader::name();
    }

    public function verify(WebhookRequest $request, array $config): Verdict
    {
        $key = trim((string)($config[self::KEY] ?? ''));
        if ($key === '') {
            return Verdict::refused('no SendGrid verification key is configured');
        }

        $signature = trim($request->header(self::SIGNATURE_HEADER));
        $timestamp = trim($request->header(self::TIMESTAMP_HEADER));

        if ($signature === '' || $timestamp === '') {
            return Verdict::refused('the SendGrid signature headers were missing');
        }

        $der = base64_decode($signature, true);
        if ($der === false || $der === '') {
            return Verdict::refused('the SendGrid signature was not base64');
        }

        $pem = self::pem($key);
        if ($pem === null) {
            return Verdict::refused('the SendGrid verification key could not be read');
        }

        // Read first rather than handing the string straight to
        // `openssl_verify`, which warns rather than returning false for a key
        // it cannot parse — and a merchant who pasted the wrong thing should
        // get a refusal in the log rather than a PHP warning in it.
        $public = @openssl_pkey_get_public($pem);
        if ($public === false) {
            return Verdict::refused('the SendGrid verification key could not be read');
        }

        // `openssl_verify` takes the DER signature as it stands; there is no
        // ASN.1 unpacking to do by hand, which is the whole reason this is
        // twelve lines rather than a hundred.
        $ok = openssl_verify($timestamp . $request->body, $der, $public, \OPENSSL_ALGO_SHA256);

        return $ok === 1
            ? Verdict::verified()
            : Verdict::refused('the SendGrid signature did not verify');
    }

    public function parse(WebhookRequest $request): Payload
    {
        try {
            $decoded = json_decode($request->body, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return Payload::unreadable('the body was not JSON');
        }

        if (!\is_array($decoded)) {
            return Payload::unreadable('the body was not a list of events');
        }

        // A bare object rather than a list turns up in their own documentation
        // and, occasionally, from their test button. Wrapped rather than
        // refused: it is unambiguously one event.
        $rows = array_is_list($decoded) ? $decoded : [$decoded];

        $events = [];
        $skipped = 0;

        foreach (\array_slice($rows, 0, self::MAX_EVENTS) as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $event = self::one($row);
            if ($event === null) {
                ++$skipped;

                continue;
            }

            $events[] = $event;
        }

        if ($events === []) {
            return Payload::nothing(sprintf('SendGrid sent %d events, none of which this store acts on', $skipped));
        }

        return Payload::of($events);
    }

    // ------------------------------------------------------------- internals

    /** @param array<array-key, mixed> $row */
    private static function one(array $row): ?Event
    {
        $name = strtolower(trim((string)($row['event'] ?? '')));
        $type = self::TYPES[$name] ?? null;

        if ($type === null) {
            return null;
        }

        $hard = null;
        if ($type === Event::BOUNCED) {
            // `blocked` is the soft one. Anything else arriving as a bounce is
            // hard, which is what `type: bounce` means. A `dropped` is not a
            // bounce and carries no `hard` at all — see the class note.
            $hard = strtolower(trim((string)($row['type'] ?? 'bounce'))) !== 'blocked';
        }

        return Event::of(
            $type,
            $hard,
            (string)($row['email'] ?? ''),
            // Their `smtp-id` is our Message-ID, brackets and all, and one of
            // their own samples ships with a leading space in front of it.
            trim((string)($row['smtp-id'] ?? '')),
            trim((string)($row['sg_message_id'] ?? '')),
            self::moment($row['timestamp'] ?? null) ?? 0,
            self::reason($row, $type),
            self::sendId($row),
        );
    }

    /**
     * The provider's own words about why.
     *
     * @param array<array-key, mixed> $row
     */
    private static function reason(array $row, string $type): ?string
    {
        $parts = array_filter([
            trim((string)($row['reason'] ?? '')),
            trim((string)($row['response'] ?? '')),
            trim((string)($row['bounce_classification'] ?? '')),
        ], static fn (string $part): bool => $part !== '');

        if ($parts !== []) {
            return implode(' — ', $parts);
        }

        return $type === Event::COMPLAINED ? 'marked as spam' : null;
    }

    /**
     * The send id, from a custom arg sitting as a top-level key on the event.
     *
     * Both spellings are tried, because a provider is free to hand a name back
     * in whatever case it stored it in and this one costs a second array
     * lookup.
     *
     * @param array<array-key, mixed> $row
     */
    private static function sendId(array $row): ?string
    {
        return SendHeader::idIn($row);
    }

    /**
     * "When did this happen", out of SendGrid's `timestamp`.
     *
     * Unix seconds, as a number or as a string depending on how their JSON
     * encoder felt that day. Answering null for a missing one rather than
     * `time()` is the point: the caller stamps a null with the moment the
     * request arrived, and it is the one that has a clock — a parser that
     * reached for `time()` would be a parser a test could not pin.
     *
     * A moment before 2000 is treated as no moment at all. It turns up in the
     * wild, from a provider's own placeholder, and would otherwise put a point
     * at the wrong end of a campaign's chart forever.
     */
    private static function moment(mixed $value): ?int
    {
        $at = null;

        if (\is_int($value)) {
            $at = $value;
        } elseif (\is_float($value)) {
            $at = (int)$value;
        } elseif (\is_string($value) && preg_match('/^\d{9,11}(\.\d+)?$/', trim($value)) === 1) {
            $at = (int)trim($value);
        }

        return $at === null || $at < self::MOMENT_FLOOR ? null : $at;
    }

    /**
     * The dashboard's bare key, wrapped in the armour OpenSSL wants.
     *
     * A key that already has the header and footer is passed through, because
     * a merchant who pasted a whole PEM should not be punished for being
     * thorough.
     */
    private static function pem(string $key): ?string
    {
        if (str_contains($key, '-----BEGIN')) {
            return $key;
        }

        $body = preg_replace('/\s+/', '', $key) ?? '';
        if ($body === '' || base64_decode($body, true) === false) {
            return null;
        }

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split($body, 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }
}
