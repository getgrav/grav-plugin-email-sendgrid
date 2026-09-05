<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSendgrid\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\DomainFacts;
use Grav\Plugin\Email\Providers\Provider;
use Grav\Plugin\Email\Providers\ProviderRegistry;
use Grav\Plugin\EmailSendgrid\Provider\SendGridProvider;
use Grav\Plugin\EmailSendgrid\Provider\SendGridReports;
use Grav\Plugin\EmailSendgrid\Provider\SendGridWebhookSetup;
use PHPUnit\Framework\TestCase;

/**
 * What this plugin tells the rest of a site about SendGrid.
 *
 * Most of these are one line each, and they are worth having anyway: every one
 * of them is read on a settings screen, and a provider answering the wrong
 * engine name or an empty key is a card that silently belongs to nobody.
 */
final class SendGridProviderTest extends TestCase
{
    public function testItAnswersForTheEngineThisPluginRegisters(): void
    {
        $provider = new SendGridProvider();

        self::assertSame(['sendgrid'], $provider->engines());
        self::assertSame('sendgrid', $provider->key());
        self::assertSame('SendGrid', $provider->label());
    }

    /** It goes into the Email plugin's registry and comes back out by both handles. */
    public function testItRegistersAndIsFoundByEngineAndByKey(): void
    {
        $registry = new ProviderRegistry();
        $provider = new SendGridProvider();
        $registry->add($provider);

        self::assertSame($provider, $registry->forEngine('sendgrid'));
        self::assertSame($provider, $registry->byKey('sendgrid'));
        self::assertInstanceOf(Provider::class, $provider);
    }

    /**
     * Headers reach the wire, and do not come back.
     *
     * Symfony's SendGrid bridge copies every header it was given into the API
     * payload's `headers` object, bypassing only the dozen SendGrid reserves,
     * and neither `List-Unsubscribe` nor `List-Unsubscribe-Post` is one of
     * them. What SendGrid never does is hand a message header back in a
     * webhook, which is what `echoesHeaders: false` says and what the note has
     * to explain, because a store reading it needs to know that the working
     * correlation path is `Message-ID`.
     */
    public function testItSaysWhatTheTransportDoesToHeaders(): void
    {
        $capabilities = (new SendGridProvider())->capabilities();

        self::assertTrue($capabilities->customHeaders);
        self::assertTrue($capabilities->unsubscribeHeaders);
        self::assertFalse($capabilities->echoesHeaders);
        self::assertStringContainsString('custom args', $capabilities->echoNote);
        self::assertStringContainsString('Message-ID', $capabilities->echoNote);
    }

    public function testItReportsDeliveriesAndCanSetItselfUp(): void
    {
        $provider = new SendGridProvider();

        self::assertInstanceOf(SendGridReports::class, $provider->reports());
        self::assertInstanceOf(SendGridWebhookSetup::class, $provider->setup());
    }

    /** Both are built once and kept, because a settings screen asks more than once. */
    public function testTheReportsAndSetupAreTheSameObjectEachTime(): void
    {
        $provider = new SendGridProvider();

        self::assertSame($provider->reports(), $provider->reports());
        self::assertSame($provider->setup(), $provider->setup());
    }

    /** What SendGrid needs a sending domain's DNS to say. */
    public function testItKnowsWhatSendGridNeedsInDns(): void
    {
        $domain = (new SendGridProvider())->domain();

        self::assertInstanceOf(DomainFacts::class, $domain);
        self::assertSame('sendgrid.net', $domain->spfInclude);
        self::assertSame('dkim.sendgrid.net', $domain->dkimZone);
        self::assertSame('sendgrid.net', $domain->returnPathZone);
    }

    /**
     * There is no domain lookup, and asking for one is an empty answer rather
     * than an error.
     */
    public function testAskingForSelectorsWithNoLookupIsAnEmptyAnswer(): void
    {
        self::assertSame([], (new SendGridProvider())->domain()->ask('example.com'));
    }

    /**
     * The instructions name the screens and the boxes.
     *
     * "Configure a webhook" is not instructions, and every one of these
     * dashboards calls it something different.
     */
    public function testTheInstructionsNameTheScreensAndTheBoxes(): void
    {
        $instructions = (new SendGridProvider())->instructions();

        self::assertStringContainsString('Mail Settings', $instructions);
        self::assertStringContainsString('Event Webhooks', $instructions);
        self::assertStringContainsString('Signed Event Webhook', $instructions);
        self::assertStringContainsString('Verification key', $instructions);
    }

    /** A language file answers instead, where something is translating. */
    public function testATranslationWinsOverTheEnglish(): void
    {
        $provider = new SendGridProvider(
            [],
            null,
            static fn (string $key, string $fallback): string => $key === 'PLUGIN_EMAIL_SENDGRID.PROVIDER_INSTRUCTIONS'
                ? 'Dans SendGrid, ouvrez Mail Settings.'
                : $fallback,
        );

        self::assertSame('Dans SendGrid, ouvrez Mail Settings.', $provider->instructions());
    }

    /** A translator that answers nothing, or throws, leaves the English standing. */
    public function testABrokenTranslatorLeavesTheEnglishStanding(): void
    {
        $empty = new SendGridProvider([], null, static fn (string $key, string $fallback): string => '   ');
        $angry = new SendGridProvider([], null, static function (string $key, string $fallback): string {
            throw new \RuntimeException('no language service');
        });

        self::assertStringContainsString('Mail Settings', $empty->instructions());
        self::assertStringContainsString('Mail Settings', $angry->instructions());
    }

    /** The config it was handed is the config it hands back. */
    public function testItKeepsThePluginsOwnConfig(): void
    {
        $config = ['api_key' => 'SG.key', 'public_key' => 'MFkw'];

        self::assertSame($config, (new SendGridProvider($config))->config());
    }

    /**
     * Nothing on the cheap path does I/O.
     *
     * Every one of these is called each time a settings screen is drawn, and a
     * network round trip in one of them is a settings screen that hangs when
     * somebody else's API is slow. Building the provider with an HTTP client
     * that fails the test if it is called is the only way to say so.
     */
    public function testTheCheapMethodsDoNoIo(): void
    {
        $provider = new SendGridProvider([], null, null, new FakeHttp());

        $provider->engines();
        $provider->key();
        $provider->label();
        $provider->capabilities();
        $provider->domain();
        $provider->instructions();
        $provider->reports();
        $provider->setup();

        self::assertTrue(true, 'a call would have thrown, because no answer was scripted');
    }
}
