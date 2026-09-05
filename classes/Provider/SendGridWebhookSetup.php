<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSendgrid\Provider;

use Grav\Plugin\Email\Providers\SetupResult;
use Grav\Plugin\Email\Providers\WebhookSetup;

/**
 * "Set up in SendGrid", which is the button rather than five pages of steps.
 *
 * A merchant who has already pasted an API key into this plugin should not then
 * have to find Mail Settings, work out which of the things called "webhooks" is
 * the Event Webhook, tick six boxes, leave five others alone, turn on Signed
 * Event Webhook, save, and copy a key back — every one of which is a place to
 * get it silently wrong. SendGrid's API does all of it, so this does.
 *
 * ## Three steps, because SendGrid makes it three
 *
 * 1. **Look for our own webhook** in `GET /user/webhooks/event/settings/all`,
 *    matched on the URL. Pressing the button twice has to update rather than
 *    create, and SendGrid's friendly name is explicitly not unique and its own
 *    documentation says not to key on it.
 * 2. **Create or update it** — `POST` or `PATCH` on
 *    `/user/webhooks/event/settings` — with every event flag set explicitly,
 *    because these endpoints replace a webhook's settings rather than merging
 *    into them.
 * 3. **Turn signing on**, with `PATCH /user/webhooks/event/settings/signed/{id}`.
 *    SendGrid says outright that signing "cannot be done at the time of
 *    creation", and that call is also the only one that hands the public key
 *    over at the moment it is minted. The key goes straight into this plugin's
 *    own config, because a webhook that signs and a store with no key to verify
 *    with is worse than no webhook at all: every event is refused and the
 *    dashboard looks finished.
 *
 * ## When the key cannot be saved
 *
 * The saving happens through a closure the plugin hands in, and where there is
 * none — or where writing the config file fails — the key is put in the message
 * instead, with a sentence saying where to paste it. Losing a key nobody was
 * shown would leave a merchant with a webhook that signs, a store that refuses
 * every event, and no way to find out why.
 *
 * ## Which key it uses
 *
 * `setup_api_key` if there is one, and the sending `api_key` otherwise. They
 * are usually the same key, and they are separate because SendGrid's own advice
 * is to send with a key restricted to Mail Send — and a Mail Send key cannot
 * read or create an event webhook. Without the second field the only way to
 * make this button work would be to put a full-access key in the field the
 * plugin sends with, which is the wrong trade.
 */
final class SendGridWebhookSetup implements WebhookSetup
{
    /** The config key holding the key this class talks to the API with, where it differs. */
    public const SETUP_KEY = 'setup_api_key';

    /** The config key holding the key the plugin sends with. */
    public const SENDING_KEY = 'api_key';

    /**
     * @param (\Closure(string $publicKey): bool)|null $saveKey writes the
     *        verification key into this plugin's own config, answering whether
     *        it got there; null when nothing can save it
     */
    public function __construct(
        private readonly SendGridApi $api,
        private readonly ?\Closure $saveKey = null,
    ) {
    }

    public function permissionsNeeded(): string
    {
        return 'The API key needs full access to Webhook, which is one of the permission groups in the key\'s own '
            . 'Restricted Access list under Settings, API Keys. A key that can only send mail cannot read or create '
            . 'event webhooks. If you send with a Mail Send key, make a second full-access key in SendGrid and paste '
            . 'it into the Setup API key field here; nothing sends with it.';
    }

    public function create(string $url, array $events, array $config): SetupResult
    {
        $apiKey = trim((string)($config[self::SETUP_KEY] ?? ''));
        if ($apiKey === '') {
            $apiKey = trim((string)($config[self::SENDING_KEY] ?? ''));
        }

        if ($apiKey === '') {
            return SetupResult::failed('No SendGrid API key is configured, so there is nothing to set the webhook up with.');
        }

        $url = trim($url);
        if ($url === '') {
            return SetupResult::failed('There is no webhook address to register yet.');
        }

        $existing = $this->api->webhooks($apiKey);
        if (!$existing['ok']) {
            return SetupResult::failed($existing['message'] . ' ' . $this->permissionsNeeded());
        }

        $id = self::ourWebhookIn($existing['webhooks'], $url);

        $written = $id === null
            ? $this->api->create($apiKey, $url, $events)
            : $this->api->update($apiKey, $id, $url, $events);

        if (!$written['ok']) {
            return SetupResult::failed($written['message'] . ' ' . $this->permissionsNeeded());
        }

        $id = $written['id'] ?? $id;

        if ($id === null) {
            // Nothing to turn signing on for. The webhook is there and unsigned
            // and the URL secret is protecting it, which is worth saying rather
            // than failing outright.
            return SetupResult::ok(
                'The webhook was created in SendGrid, but SendGrid did not hand back an id for it, so signing could '
                . 'not be turned on. Turn on Signed Event Webhook for it by hand and paste the verification key into '
                . 'the Verification key field here.'
            );
        }

        $signed = $this->api->enableSigning($apiKey, $id);
        if (!$signed['ok']) {
            return SetupResult::ok(
                'The webhook is set up in SendGrid, but signing could not be turned on: ' . $signed['message']
                . ' Turn on Signed Event Webhook for it by hand and paste the verification key into the Verification '
                . 'key field here.',
                $id
            );
        }

        $saved = $this->saveKey !== null && $this->store($signed['public_key']);

        return SetupResult::ok(
            $saved
                ? 'The webhook is set up in SendGrid, signing is on, and the verification key has been saved here. '
                    . 'Delivery reports should start arriving with the next campaign.'
                : 'The webhook is set up in SendGrid and signing is on, but the verification key could not be saved '
                    . 'here. Paste this into the Verification key field and save: ' . $signed['public_key'],
            $id
        );
    }

    // ------------------------------------------------------------- internals

    /** Whether the key got into this plugin's config. Never throws; a failed write is a longer message. */
    private function store(string $publicKey): bool
    {
        try {
            return ($this->saveKey)($publicKey) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The id of the webhook already pointed at this address, or null.
     *
     * Matched on the URL with the trailing slash and the case of the host
     * ignored, because those are the two ways the same address is written twice
     * and a store does not want two webhooks for them.
     *
     * @param list<array<string, mixed>> $webhooks
     */
    private static function ourWebhookIn(array $webhooks, string $url): ?string
    {
        $wanted = self::normalise($url);

        foreach ($webhooks as $webhook) {
            if (self::normalise((string)($webhook['url'] ?? '')) === $wanted) {
                return SendGridApi::idIn($webhook);
            }
        }

        return null;
    }

    private static function normalise(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);

        if (!\is_array($parts) || !isset($parts['host'])) {
            return strtolower($url);
        }

        $host = strtolower($parts['host']);
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port . rtrim($parts['path'] ?? '', '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
