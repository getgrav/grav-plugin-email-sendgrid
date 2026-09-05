<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSendgrid\Provider;

/**
 * SendGrid's Webhooks API, as much of it as creating one webhook needs.
 *
 * Documentation: `twilio.com/docs/sendgrid/api-reference/webhooks/` — "Get All
 * Event Webhooks", "Create an Event Webhook" and "Toggle Signature
 * Verification for an Event Webhook". Read 2026-09-04.
 *
 * ## Four calls, and why it is four rather than one
 *
 * SendGrid separates creating a webhook from turning its signature on, and says
 * so outright: "Enabling signature verification for your webhook is a separate
 * process and cannot be done at the time of creation with this endpoint." So a
 * webhook that verifies takes two calls, and the second one is also the only
 * way to get the public key — it is returned by the toggle and nowhere else at
 * the moment it is minted.
 *
 * The listing call is the third, and it is what stops a second press of the
 * button leaving an account with two webhooks posting the same events at the
 * same address. SendGrid's friendly name is explicitly not unique and its own
 * documentation says not to key on it, so the match is on the URL.
 *
 * ## The account's region
 *
 * `https://api.sendgrid.com` is the base for global users and subusers.
 * EU regional subusers are on `https://api.eu.sendgrid.com`, and this class
 * uses the global one because that is what the plugin sends through. An EU
 * store gets a 401 here and the merchant is told what it said; a region setting
 * is the honest fix and is noted for a later release.
 *
 * ## What it does not do
 *
 * It does not delete a webhook, ever. A merchant who wants one gone removes it
 * in SendGrid's own dashboard, where they can see what else is pointed at it.
 * A plugin deleting a webhook it did not create is exactly the thing that takes
 * a store's other integration down on a Friday afternoon.
 *
 * It does not store the API key. The key is a config value the merchant pasted
 * and this class is handed it per call.
 */
final class SendGridApi
{
    /** The base for global users and subusers. */
    public const BASE = 'https://api.sendgrid.com/v3';

    /**
     * The events this contract knows, in SendGrid's own spelling.
     *
     * `bounce` covers both of SendGrid's bounce events — a hard `bounce` and a
     * soft `blocked` arrive under the one flag — and `spam_report` is the
     * complaint. Everything not named here is asked for as false, so a merchant
     * who once ticked `processed` in the dashboard stops being sent forty
     * thousand events a month that nothing acts on.
     *
     * @var array<string, string> the contract's event type => SendGrid's flag
     */
    public const EVENTS = [
        'delivered' => 'delivered',
        'bounced' => 'bounce',
        'complained' => 'spam_report',
        'opened' => 'open',
        'clicked' => 'click',
        'dropped' => 'dropped',
    ];

    /**
     * Every flag the create and update endpoints take, so each one is sent
     * explicitly.
     *
     * Sending the whole set matters on an update: these endpoints replace a
     * webhook's settings rather than merging into them, so a flag left out of
     * the body is a flag turned off. Writing them all down is also what makes
     * "we asked for exactly these six and no others" something a test can read.
     *
     * @var list<string>
     */
    public const ALL_FLAGS = [
        'delivered',
        'bounce',
        'spam_report',
        'open',
        'click',
        'dropped',
        'deferred',
        'processed',
        'unsubscribe',
        'group_unsubscribe',
        'group_resubscribe',
    ];

    /** What the webhook is called in SendGrid's own list, for a merchant reading it. */
    public const FRIENDLY_NAME = 'Grav delivery reports';

    public function __construct(private readonly Http $http)
    {
    }

    /**
     * Every event webhook on the account.
     *
     * @return array{ok: bool, webhooks: list<array<string, mixed>>, message: string}
     */
    public function webhooks(string $apiKey): array
    {
        $answer = $this->call('GET', '/user/webhooks/event/settings/all', $apiKey);

        if (!$answer['ok']) {
            return ['ok' => false, 'webhooks' => [], 'message' => $answer['message']];
        }

        $rows = $answer['body']['webhooks'] ?? null;
        if (!\is_array($rows)) {
            return ['ok' => false, 'webhooks' => [], 'message' => 'SendGrid answered without a list of webhooks'];
        }

        $webhooks = [];
        foreach ($rows as $row) {
            if (\is_array($row)) {
                $webhooks[] = $row;
            }
        }

        return ['ok' => true, 'webhooks' => $webhooks, 'message' => ''];
    }

    /**
     * Create a webhook at this address for these events.
     *
     * @param list<string> $events from the contract's `Event::TYPES`
     * @return array{ok: bool, id: string|null, message: string}
     */
    public function create(string $apiKey, string $url, array $events): array
    {
        $answer = $this->call('POST', '/user/webhooks/event/settings', $apiKey, self::settings($url, $events));

        if (!$answer['ok']) {
            return ['ok' => false, 'id' => null, 'message' => $answer['message']];
        }

        return ['ok' => true, 'id' => self::idIn($answer['body']), 'message' => ''];
    }

    /**
     * Point an existing webhook at this address and these events.
     *
     * @param list<string> $events from the contract's `Event::TYPES`
     * @return array{ok: bool, id: string|null, message: string}
     */
    public function update(string $apiKey, string $id, string $url, array $events): array
    {
        $answer = $this->call(
            'PATCH',
            '/user/webhooks/event/settings/' . rawurlencode($id),
            $apiKey,
            self::settings($url, $events)
        );

        if (!$answer['ok']) {
            return ['ok' => false, 'id' => null, 'message' => $answer['message']];
        }

        return ['ok' => true, 'id' => self::idIn($answer['body']) ?? $id, 'message' => ''];
    }

    /**
     * Turn signature verification on, and read back the key that verifies it.
     *
     * This is the only place the public key is handed over at the moment it is
     * minted, which is why the setup flow always makes this call even for a
     * webhook that already existed.
     *
     * @return array{ok: bool, public_key: string, message: string}
     */
    public function enableSigning(string $apiKey, string $id): array
    {
        $answer = $this->call(
            'PATCH',
            '/user/webhooks/event/settings/signed/' . rawurlencode($id),
            $apiKey,
            ['enabled' => true]
        );

        if (!$answer['ok']) {
            return ['ok' => false, 'public_key' => '', 'message' => $answer['message']];
        }

        $key = trim((string)($answer['body']['public_key'] ?? ''));

        return $key === ''
            ? ['ok' => false, 'public_key' => '', 'message' => 'SendGrid turned signing on but did not hand back a verification key.']
            : ['ok' => true, 'public_key' => $key, 'message' => ''];
    }

    // ------------------------------------------------------------- internals

    /**
     * The settings body both the create and the update endpoint take.
     *
     * @param list<string> $events
     * @return array<string, mixed>
     */
    private static function settings(string $url, array $events): array
    {
        $wanted = [];
        foreach ($events as $event) {
            $flag = self::EVENTS[strtolower(trim((string)$event))] ?? null;
            if ($flag !== null) {
                $wanted[$flag] = true;
            }
        }

        $body = ['enabled' => true, 'url' => $url, 'friendly_name' => self::FRIENDLY_NAME];

        foreach (self::ALL_FLAGS as $flag) {
            $body[$flag] = $wanted[$flag] ?? false;
        }

        return $body;
    }

    /**
     * One call, with SendGrid's error envelope read into a sentence.
     *
     * Their v3 errors are `{"errors": [{"message": "...", "field": "..."}]}`
     * and their own words are what a merchant needs — "authorization required"
     * and "access forbidden" mean two quite different things to somebody
     * looking at an API key page, and neither of them is "403".
     *
     * @param array<string, mixed>|null $body
     * @return array{ok: bool, body: array<array-key, mixed>, message: string}
     */
    private function call(string $method, string $path, string $apiKey, ?array $body = null): array
    {
        $answer = $this->http->json($method, self::BASE . $path, $body, [
            'Authorization' => 'Bearer ' . $apiKey,
        ]);

        if ($answer['status'] === 0) {
            return [
                'ok' => false,
                'body' => [],
                'message' => $answer['error'] !== ''
                    ? 'SendGrid could not be reached: ' . $answer['error']
                    : 'SendGrid could not be reached.',
            ];
        }

        $decoded = $answer['body'] ?? [];
        $said = self::errorIn($decoded);

        if ($answer['status'] < 200 || $answer['status'] >= 300) {
            return [
                'ok' => false,
                'body' => [],
                'message' => $said !== ''
                    ? sprintf('SendGrid refused it: %s', $said)
                    : sprintf('SendGrid answered %d.', $answer['status']),
            ];
        }

        // A 2xx carrying an errors array happens on their partial-success
        // paths, and treating it as a success would leave a merchant with a
        // green button and no webhook.
        if ($said !== '') {
            return ['ok' => false, 'body' => [], 'message' => sprintf('SendGrid refused it: %s', $said)];
        }

        return ['ok' => true, 'body' => $decoded, 'message' => ''];
    }

    /**
     * SendGrid's own words about what went wrong, or an empty string.
     *
     * @param array<array-key, mixed> $body
     */
    private static function errorIn(array $body): string
    {
        $errors = $body['errors'] ?? null;
        $said = [];

        if (\is_array($errors)) {
            foreach ($errors as $error) {
                $message = \is_array($error) ? trim((string)($error['message'] ?? '')) : trim((string)$error);
                if ($message !== '') {
                    $said[] = $message;
                }
            }
        }

        if ($said === []) {
            $single = trim((string)($body['error'] ?? ''));
            if ($single !== '') {
                $said[] = $single;
            }
        }

        return implode('; ', $said);
    }

    /**
     * A webhook id out of an answer.
     *
     * SendGrid's ids are strings and have been since the multiple-webhooks API
     * arrived, so this does not turn one into a number — an id that stopped
     * round-tripping would break the second press of the button and nothing
     * would say why.
     *
     * @param array<array-key, mixed> $body
     */
    public static function idIn(array $body): ?string
    {
        $id = $body['id'] ?? null;

        if (\is_int($id)) {
            $id = (string)$id;
        }

        if (!\is_string($id)) {
            return null;
        }

        $id = trim($id);

        return $id === '' ? null : $id;
    }
}
