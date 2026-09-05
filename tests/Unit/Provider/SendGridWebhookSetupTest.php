<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSendgrid\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\EmailSendgrid\Provider\SendGridApi;
use Grav\Plugin\EmailSendgrid\Provider\SendGridWebhookSetup;
use PHPUnit\Framework\TestCase;

/**
 * The Set up button, against a scripted SendGrid.
 *
 * Three things are worth pinning here and each of them is a real way this goes
 * wrong on somebody's store: the calls are made in the order SendGrid requires
 * and with the flags it needs, a refused key comes back as SendGrid's own
 * sentence rather than a number, and the verification key that only exists for
 * one round trip actually gets saved.
 */
final class SendGridWebhookSetupTest extends TestCase
{
    private const URL = 'https://store.example/newsletter/webhook/sendgrid/s3cret';

    /** @var list<string> */
    private const EVENTS = [
        Event::DELIVERED,
        Event::BOUNCED,
        Event::COMPLAINED,
        Event::OPENED,
        Event::CLICKED,
        Event::DROPPED,
    ];

    public function testItCreatesTheWebhookTurnsSigningOnAndSavesTheKey(): void
    {
        $http = FakeHttp::happy('wh_42', 'MFkwEwYHKoZIzj0CAQYIKoZ');
        $saved = null;

        $result = $this->button($http, function (string $key) use (&$saved): bool {
            $saved = $key;

            return true;
        })->create(self::URL, self::EVENTS, ['api_key' => 'SG.key']);

        self::assertTrue($result->ok, $result->message);
        self::assertSame('wh_42', $result->webhookId);
        self::assertSame('MFkwEwYHKoZIzj0CAQYIKoZ', $saved, 'the key is handed over once and has to be kept');
        self::assertStringContainsString('saved here', $result->message);

        self::assertCount(3, $http->calls, 'look, create, sign');

        [$look, $create, $sign] = $http->calls;

        self::assertSame('GET', $look['method']);
        self::assertSame(SendGridApi::BASE . '/user/webhooks/event/settings/all', $look['url']);
        self::assertSame('Bearer SG.key', $look['headers']['Authorization']);

        self::assertSame('POST', $create['method']);
        self::assertSame(SendGridApi::BASE . '/user/webhooks/event/settings', $create['url']);
        self::assertSame(self::URL, $create['body']['url']);
        self::assertTrue($create['body']['enabled']);

        self::assertSame('PATCH', $sign['method']);
        self::assertSame(SendGridApi::BASE . '/user/webhooks/event/settings/signed/wh_42', $sign['url']);
        self::assertSame(['enabled' => true], $sign['body']);
    }

    /**
     * The six events a store acts on are asked for and the five it does not are
     * asked for as false.
     *
     * Both halves matter. Leaving `processed` out of the body would leave it on
     * for a merchant who once ticked it, because these endpoints replace a
     * webhook's settings rather than merging into them — and `processed` alone
     * is one event per message sent.
     */
    public function testItAsksForExactlyTheEventsAStoreActsOn(): void
    {
        $http = FakeHttp::happy();

        $this->button($http)->create(self::URL, self::EVENTS, ['api_key' => 'SG.key']);

        $body = $http->call(1)['body'];

        foreach (['delivered', 'bounce', 'spam_report', 'open', 'click', 'dropped'] as $flag) {
            self::assertTrue($body[$flag], "{$flag} should be on");
        }

        foreach (['processed', 'deferred', 'unsubscribe', 'group_unsubscribe', 'group_resubscribe'] as $flag) {
            self::assertFalse($body[$flag], "{$flag} should be explicitly off");
        }
    }

    /**
     * Pressing the button twice updates the webhook that is already pointed at
     * the address rather than making a second one.
     */
    public function testASecondPressUpdatesTheWebhookRatherThanCreatingAnother(): void
    {
        $http = new FakeHttp([
            FakeHttp::answer(200, ['webhooks' => [
                ['id' => 'wh_other', 'url' => 'https://somewhere.else/hook'],
                // The same address, written with a trailing slash and a shouty
                // host, which is the ordinary way one URL is written twice.
                ['id' => 'wh_ours', 'url' => 'https://STORE.example/newsletter/webhook/sendgrid/s3cret/'],
            ]]),
            FakeHttp::answer(200, ['id' => 'wh_ours']),
            FakeHttp::answer(200, ['id' => 'wh_ours', 'public_key' => 'MFkw']),
        ]);

        $result = $this->button($http)->create(self::URL, self::EVENTS, ['api_key' => 'SG.key']);

        self::assertTrue($result->ok, $result->message);
        self::assertSame('wh_ours', $result->webhookId);
        self::assertSame('PATCH', $http->call(1)['method']);
        self::assertSame(SendGridApi::BASE . '/user/webhooks/event/settings/wh_ours', $http->call(1)['url']);
    }

    /**
     * A key that cannot manage webhooks comes back in SendGrid's own words,
     * with the permission it needs named.
     */
    public function testARefusedKeyAnswersInSendGridsOwnWords(): void
    {
        $http = new FakeHttp([
            FakeHttp::answer(403, ['errors' => [['message' => 'access forbidden', 'field' => null]]]),
        ]);

        $result = $this->button($http)->create(self::URL, self::EVENTS, ['api_key' => 'SG.mailonly']);

        self::assertFalse($result->ok);
        self::assertStringContainsString('access forbidden', $result->message);
        self::assertStringContainsString('Webhook', $result->message, 'and the permission it needs is named');
        self::assertNull($result->webhookId);
        self::assertCount(1, $http->calls, 'it stops at the first refusal rather than trying to create anything');
    }

    /** A 401 on the create call says so too, rather than reporting a number. */
    public function testARefusalOnTheCreateCallIsAlsoASentence(): void
    {
        $http = new FakeHttp([
            FakeHttp::answer(200, ['webhooks' => []]),
            FakeHttp::answer(401, ['errors' => [['message' => 'authorization required']]]),
        ]);

        $result = $this->button($http)->create(self::URL, self::EVENTS, ['api_key' => 'SG.key']);

        self::assertFalse($result->ok);
        self::assertStringContainsString('authorization required', $result->message);
    }

    /** SendGrid being unreachable is a sentence naming the network, not a stack trace. */
    public function testANetworkFailureIsASentence(): void
    {
        $http = new FakeHttp([
            FakeHttp::answer(0, null, 'Could not resolve host: api.sendgrid.com'),
        ]);

        $result = $this->button($http)->create(self::URL, self::EVENTS, ['api_key' => 'SG.key']);

        self::assertFalse($result->ok);
        self::assertStringContainsString('could not be reached', $result->message);
        self::assertStringContainsString('Could not resolve host', $result->message);
    }

    /**
     * A webhook that got made but whose key could not be saved hands the key to
     * the merchant instead of losing it.
     *
     * SendGrid mints the verification key once, at the moment signing is turned
     * on, and never shows it again in that call. Swallowing it would leave a
     * store with a signed webhook, an empty key field and every event refused.
     */
    public function testAKeyThatCouldNotBeSavedIsPutInTheMessage(): void
    {
        $result = $this->button(FakeHttp::happy('wh_1', 'MFkwPUBLIC'), static fn (string $key): bool => false)
            ->create(self::URL, self::EVENTS, ['api_key' => 'SG.key']);

        self::assertTrue($result->ok);
        self::assertStringContainsString('MFkwPUBLIC', $result->message);
        self::assertStringContainsString('Verification key', $result->message);
    }

    /** A saver that throws is the same as one that answered no. */
    public function testASaverThatThrowsDoesNotTakeTheButtonDownWithIt(): void
    {
        $result = $this->button(
            FakeHttp::happy('wh_1', 'MFkwPUBLIC'),
            static function (string $key): bool {
                throw new \RuntimeException('the config directory is read only');
            }
        )->create(self::URL, self::EVENTS, ['api_key' => 'SG.key']);

        self::assertTrue($result->ok);
        self::assertStringContainsString('MFkwPUBLIC', $result->message);
    }

    /**
     * Signing failing leaves the webhook in place and says what to do by hand.
     *
     * A webhook that posts is worth more than no webhook, and the merchant is
     * two clicks from the rest of it.
     */
    public function testSigningFailingStillLeavesAWorkingWebhook(): void
    {
        $http = new FakeHttp([
            FakeHttp::answer(200, ['webhooks' => []]),
            FakeHttp::answer(201, ['id' => 'wh_1']),
            FakeHttp::answer(403, ['errors' => [['message' => 'access forbidden']]]),
        ]);

        $result = $this->button($http)->create(self::URL, self::EVENTS, ['api_key' => 'SG.key']);

        self::assertTrue($result->ok);
        self::assertSame('wh_1', $result->webhookId);
        self::assertStringContainsString('Signed Event Webhook', $result->message);
    }

    /** The setup key is used when there is one, because a Mail Send key cannot do this. */
    public function testTheSetupKeyIsPreferredOverTheSendingKey(): void
    {
        $http = FakeHttp::happy();

        $this->button($http)->create(self::URL, self::EVENTS, [
            'api_key' => 'SG.mailonly',
            'setup_api_key' => 'SG.fullaccess',
        ]);

        self::assertSame('Bearer SG.fullaccess', $http->call(0)['headers']['Authorization']);
    }

    /** No key at all is a plain sentence rather than a call nobody could make. */
    public function testNoKeyAtAllIsRefusedBeforeAnythingIsCalled(): void
    {
        $http = new FakeHttp();

        $result = $this->button($http)->create(self::URL, self::EVENTS, ['api_key' => '  ']);

        self::assertFalse($result->ok);
        self::assertStringContainsString('No SendGrid API key', $result->message);
        self::assertSame([], $http->calls);
    }

    /** No address to register yet is its own sentence. */
    public function testNoUrlIsRefusedBeforeAnythingIsCalled(): void
    {
        $http = new FakeHttp();

        $result = $this->button($http)->create('', self::EVENTS, ['api_key' => 'SG.key']);

        self::assertFalse($result->ok);
        self::assertStringContainsString('no webhook address', $result->message);
        self::assertSame([], $http->calls);
    }

    /** An answer that is a 2xx but carries errors is not a success. */
    public function testATwoHundredCarryingErrorsIsNotASuccess(): void
    {
        $http = new FakeHttp([
            FakeHttp::answer(200, ['webhooks' => []]),
            FakeHttp::answer(200, ['errors' => [['message' => 'url is invalid']]]),
        ]);

        $result = $this->button($http)->create(self::URL, self::EVENTS, ['api_key' => 'SG.key']);

        self::assertFalse($result->ok);
        self::assertStringContainsString('url is invalid', $result->message);
    }

    /** The permission sentence names the group a merchant will actually find. */
    public function testThePermissionSentenceNamesTheGroupInTheDashboard(): void
    {
        $needed = $this->button(new FakeHttp())->permissionsNeeded();

        self::assertStringContainsString('Webhook', $needed);
        self::assertStringContainsString('API Keys', $needed);
        self::assertStringContainsString('Mail Send', $needed);
    }

    // ------------------------------------------------------------- internals

    /** @param (\Closure(string): bool)|null $saveKey */
    private function button(FakeHttp $http, ?\Closure $saveKey = null): SendGridWebhookSetup
    {
        return new SendGridWebhookSetup(new SendGridApi($http), $saveKey ?? static fn (string $key): bool => true);
    }
}
