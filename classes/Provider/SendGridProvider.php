<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSendgrid\Provider;

use Grav\Plugin\Email\Providers\Capabilities;
use Grav\Plugin\Email\Providers\DeliveryReports;
use Grav\Plugin\Email\Providers\DomainFacts;
use Grav\Plugin\Email\Providers\Provider;
use Grav\Plugin\Email\Providers\WebhookSetup;

/**
 * Everything SendGrid knows about itself, answered by SendGrid's own plugin.
 *
 * Registered on the Email plugin's `onEmailProviders` event by
 * {@see \Grav\Plugin\EmailSendgridPlugin}. Nothing else on a site carries a
 * SendGrid parser, a table of SendGrid's DNS, or a branch for what SendGrid
 * does to a header — they ask.
 *
 * This class is built while a settings screen is being drawn, so it does no I/O
 * of its own. The one thing here that talks to SendGrid is
 * {@see SendGridWebhookSetup}, which is behind a button, and it is only
 * constructed when something asks for it.
 */
final class SendGridProvider implements Provider
{
    /** The engine key this plugin registers on `onEmailEngines`. */
    public const ENGINE = 'sendgrid';

    private ?SendGridReports $reports = null;

    private ?SendGridWebhookSetup $setup = null;

    /**
     * @param array<string, mixed> $config this plugin's own config block
     * @param (\Closure(string $publicKey): bool)|null $saveKey writes the
     *        verification key back into this plugin's config after setup
     * @param (\Closure(string $key, string $fallback): string)|null $translate
     *        a language lookup; null means the English text is used as it
     *        stands, which is what a test wants
     * @param Http|null $http the outbound client; null is cURL
     */
    public function __construct(
        private readonly array $config = [],
        private readonly ?\Closure $saveKey = null,
        private readonly ?\Closure $translate = null,
        private readonly ?Http $http = null,
    ) {
    }

    public function engines(): array
    {
        return [self::ENGINE];
    }

    public function key(): string
    {
        return self::ENGINE;
    }

    public function label(): string
    {
        return 'SendGrid';
    }

    /**
     * What this transport does to a message on the way out.
     *
     * **Custom headers reach the wire.** Over SMTP because the headers are the
     * message; over the API because Symfony's SendGrid bridge copies every
     * header it was given into the payload's `headers` object, bypassing only
     * the dozen SendGrid reserves. `List-Unsubscribe` and
     * `List-Unsubscribe-Post` are not on that reserved list and survive both
     * ways, which is what puts the unsubscribe button next to the sender name
     * in Gmail.
     *
     * **Headers do not come back, though.** SendGrid's Event Webhook carries no
     * message headers at all, on any event. What it does carry is the send's
     * custom args, as top-level keys on the event object — so tying a bounce to
     * one recipient of one campaign through a header means setting a custom arg
     * of the same name rather than only a header, and over SMTP that is the
     * `unique_args` map inside `X-SMTPAPI`. The `Message-ID` path works without
     * any of that and is the one to rely on: SendGrid echoes it as `smtp-id`,
     * and it survives the two gaps SendGrid documents in its own ids.
     */
    public function capabilities(): Capabilities
    {
        return new Capabilities(
            customHeaders: true,
            unsubscribeHeaders: true,
            echoesHeaders: false,
            echoNote: $this->say(
                'PLUGIN_EMAIL_SENDGRID.ECHO_NOTE',
                'SendGrid does not send message headers back in its webhooks. It sends the message\'s custom args '
                . 'instead, as top-level fields on the event, so a send id has to go out as a custom arg — over SMTP '
                . 'that is the unique_args map inside the X-SMTPAPI header. Correlating on Message-ID needs none of '
                . 'that and works on every event, because SendGrid echoes it as smtp-id.'
            ),
        );
    }

    public function reports(): ?DeliveryReports
    {
        return $this->reports ??= new SendGridReports();
    }

    public function setup(): ?WebhookSetup
    {
        return $this->setup ??= new SendGridWebhookSetup(
            new SendGridApi($this->http ?? new CurlHttp()),
            $this->saveKey,
        );
    }

    /**
     * What SendGrid needs a sending domain's DNS to say.
     *
     * SPF ends up at `sendgrid.net`, the DKIM selectors are CNAMEs into
     * `dkim.sendgrid.net`, and a custom return path is a CNAME into
     * `sendgrid.net` as well. The selectors themselves are per domain and per
     * account — SendGrid mints two of them, `s1` and `s2`, under a subdomain it
     * chooses — so there is nothing here to guess at, which is what
     * {@see DomainFacts} is about.
     *
     * There is no lookup closure. SendGrid's Domain Authentication API would
     * answer the real selectors for a domain, and wiring it up needs an API key
     * with a second permission group on it; it is worth doing and it is not
     * this release.
     */
    public function domain(): DomainFacts
    {
        return new DomainFacts(
            spfInclude: 'sendgrid.net',
            dkimZone: 'dkim.sendgrid.net',
            returnPathZone: 'sendgrid.net',
        );
    }

    public function instructions(): string
    {
        return $this->say(
            'PLUGIN_EMAIL_SENDGRID.PROVIDER_INSTRUCTIONS',
            'In SendGrid, go to Settings, then Mail Settings, then Event Webhooks, and add a webhook with this '
            . 'address. Tick Delivered, Bounced, Dropped, Spam Reports, Opened and Clicked, and leave Processed, '
            . 'Deferred and the three unsubscribe events off, because nothing here acts on them. Then turn on Signed '
            . 'Event Webhook and press Save: the verification key does not exist until that first save. Copy the key '
            . 'it shows into the Verification key field in this plugin\'s settings, exactly as SendGrid prints it, '
            . 'and the PEM wrapper will be added for you.'
        );
    }

    // ------------------------------------------------------------- internals

    /** The translated string, or the English one when nothing is translating. */
    private function say(string $key, string $english): string
    {
        if ($this->translate === null) {
            return $english;
        }

        try {
            $said = trim(($this->translate)($key, $english));
        } catch (\Throwable) {
            return $english;
        }

        return $said === '' ? $english : $said;
    }

    /**
     * This plugin's own config, for a caller that wants to hand it straight to
     * `verify()` or `create()`.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }
}
