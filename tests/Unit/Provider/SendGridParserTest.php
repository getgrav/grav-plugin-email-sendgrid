<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSendgrid\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\Email\Providers\SendHeader;
use Grav\Plugin\Email\Providers\WebhookRequest;
use Grav\Plugin\EmailSendgrid\Provider\SendGridReports;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SendGrid's payload format, in the vocabulary every provider shares.
 *
 * The whole point of the contract is that a `delivered` from SendGrid and a
 * `Delivery` from Amazon arrive at whatever is recording them as the same word,
 * and that a permanent failure is `hard` whether the provider called it
 * `Permanent`, `HardBounce`, `permanent` or `bounce`. This is the test that
 * says so for SendGrid, and it says it against **SendGrid's own documented
 * sample payloads**, which live in `tests/fixtures/webhooks/sendgrid/`.
 *
 * That matters more than it sounds. Every one of these providers has renamed a
 * field at some point, and a parser written against a payload somebody
 * remembered is a parser that reads null forever without failing anything. A
 * fixture taken from the documentation and a test that reads it is the only
 * thing that turns "SendGrid changed `smtp-id`" from a store that quietly stops
 * recording bounces into a red bar.
 *
 * The fixtures are copied exactly out of SendGrid's own documentation, read
 * 2026-09-04, and moved here from the KahunaCart Newsletter add-on, which is
 * where they were written and where this parser used to live.
 */
final class SendGridParserTest extends TestCase
{
    /**
     * Every documented sample, and the normalised event it has to become.
     *
     * Written out longhand rather than generated, because a table built from
     * the same constants the parser reads would agree with the code however
     * wrong both were.
     *
     * @return iterable<string, array{0: string, 1: array<string, mixed>|null}>
     */
    public static function samples(): iterable
    {
        yield 'delivered' => ['delivered', [
            'type' => Event::DELIVERED,
            'email' => 'alex@example.com',
        ]];

        // `type: bounce` is the hard one; `smtp-id` is our own Message-ID with
        // the brackets on, which `Event::of()` takes off.
        yield 'hard bounce' => ['bounce', [
            'type' => Event::BOUNCED,
            'hard' => true,
            'email' => 'alex@example.com',
            'message_id' => '14c5d75ce93.dfd.64b469@ismtpd-555',
        ]];

        // `type: blocked` is the soft one, and it arrives as the same event.
        yield 'block' => ['blocked', [
            'type' => Event::BOUNCED,
            'hard' => false,
        ]];

        // Dropped is SendGrid refusing to try at all. The contract has its own
        // word for that, and this parser uses it rather than folding it into a
        // bounce; `hard` stays null because nothing was ever handed to a
        // receiving server for it to be permanent or temporary about.
        yield 'dropped' => ['dropped', [
            'type' => Event::DROPPED,
            'hard' => null,
            'email' => 'alex@example.com',
            'reason' => 'Bounced Address',
        ]];

        yield 'complaint' => ['spamreport', [
            'type' => Event::COMPLAINED,
        ]];

        yield 'open' => ['open', ['type' => Event::OPENED]];
        yield 'click' => ['click', ['type' => Event::CLICKED]];
    }

    /**
     * @param array<string, mixed>|null $expected null means "read no events"
     */
    #[DataProvider('samples')]
    public function testTheDocumentedSampleBecomesTheNormalisedEvent(string $fixture, ?array $expected): void
    {
        $payload = (new SendGridReports())->parse(self::request($fixture));

        if ($expected === null) {
            self::assertTrue($payload->isEmpty(), 'this sample is not one a store acts on');
            self::assertNotSame('', $payload->note, 'and it should say why');

            return;
        }

        self::assertCount(1, $payload->events, 'one documented sample is one event');
        $event = $payload->events[0]->toArray();

        foreach ($expected as $field => $value) {
            self::assertSame($value, $event[$field], "sendgrid/{$fixture}: {$field}");
        }
    }

    /**
     * Every sample carries a moment, because a chart with a null on it is a
     * chart with a gap in it.
     *
     * A date format nobody parsed would otherwise read as zero and be stamped
     * with the receiver's clock without anybody noticing.
     */
    #[DataProvider('samples')]
    public function testEverySampleCarriesAMomentThatWasActuallyRead(string $fixture, ?array $expected): void
    {
        if ($expected === null) {
            self::assertTrue(true);

            return;
        }

        $event = (new SendGridReports())->parse(self::request($fixture))->events[0];

        self::assertGreaterThan(
            SendGridReports::MOMENT_FLOOR,
            $event->at,
            "sendgrid/{$fixture}: the timestamp was not read, so the receiver's clock would stand in for it"
        );
    }

    /**
     * An event type nobody is asked about is a 200 and a note, not an error.
     *
     * SendGrid sends far more than the six a store acts on — `processed`,
     * `deferred`, `unsubscribe`, `group_unsubscribe`, `account_status_change` —
     * and a merchant who ticked every box in the dashboard should get a quiet
     * log line rather than a refusal SendGrid then retries for a week.
     */
    public function testAnEventTypeWeDoNotActOnIsSkippedRatherThanRefused(): void
    {
        foreach ([
            'processed',
            'deferred',
            'unsubscribe',
            'group_unsubscribe',
            'group_resubscribe',
            'account_status_change',
        ] as $fixture) {
            $payload = (new SendGridReports())->parse(self::request($fixture));

            self::assertTrue($payload->isEmpty(), "{$fixture} should be skipped");
            self::assertFalse($payload->unreadable, "{$fixture} is a good payload, just not an interesting one");
            self::assertMatchesRegularExpression('/acts on/', $payload->note, $fixture);
        }
    }

    /**
     * A body that is not JSON at all is a note and no events, never an
     * exception.
     *
     * The caller answers 200 to it and logs the first few hundred bytes. A
     * parser that threw would be a 500, and a 500 is what makes a provider
     * retry for days.
     */
    public function testAnUnreadableBodyIsANoteRatherThanAnException(): void
    {
        foreach (['this is not json', '', '{"broken": ', 'null', '"a string"'] as $body) {
            $payload = (new SendGridReports())->parse(new WebhookRequest(body: $body));

            self::assertTrue($payload->isEmpty(), $body);
            self::assertTrue($payload->unreadable, $body);
            self::assertNotSame('', $payload->note, $body);
        }
    }

    /**
     * SendGrid's payload is a list where everybody else's is an object, and it
     * is a list even when there is one event in it.
     */
    public function testItReadsABatchOfEvents(): void
    {
        $body = (string)json_encode([
            ['event' => 'delivered', 'email' => 'a@example.com', 'timestamp' => 1737000000, 'smtp-id' => '<one@x>'],
            ['event' => 'bounce', 'type' => 'bounce', 'email' => 'b@example.com', 'timestamp' => 1737000001, 'smtp-id' => '<two@x>'],
            ['event' => 'deferred', 'email' => 'c@example.com', 'timestamp' => 1737000002],
        ]);

        $payload = (new SendGridReports())->parse(new WebhookRequest(body: $body));

        self::assertCount(2, $payload->events, 'the deferred one is skipped and the other two are not');
        self::assertSame(Event::DELIVERED, $payload->events[0]->type);
        self::assertSame(Event::BOUNCED, $payload->events[1]->type);
    }

    /**
     * A bare object rather than a list turns up in SendGrid's own
     * documentation, and occasionally from their test button. Wrapped rather
     * than refused: it is unambiguously one event.
     */
    public function testABareObjectIsReadAsOneEvent(): void
    {
        $payload = (new SendGridReports())->parse(self::request('processed-with-unique-args'));

        self::assertTrue($payload->isEmpty(), 'processed is not one a store acts on');
        self::assertFalse($payload->unreadable, 'but it was read perfectly well');
    }

    /**
     * A SendGrid custom argument arrives as a top-level key on the event rather
     * than nested, which is the one thing about their unique args that is easy
     * to get wrong.
     */
    public function testItReadsTheSendIdOutOfATopLevelCustomArgument(): void
    {
        $body = (string)json_encode([[
            'event' => 'delivered',
            'email' => 'a@example.com',
            'timestamp' => 1737000000,
            'X-Grav-Send-Id' => '77',
        ]]);

        $event = (new SendGridReports())->parse(new WebhookRequest(body: $body))->events[0];

        self::assertSame('77', $event->sendId, 'the contract carries it as the string the provider handed back');
        self::assertSame(SendHeader::name(), (new SendGridReports())->sendHeader(), 'the Email plugin names it');
        self::assertSame('X-Grav-Send-Id', (new SendGridReports())->sendHeader());
    }

    /** The same argument spelled in lower case, which is a provider's to choose. */
    public function testItReadsTheSendIdSpelledInLowerCase(): void
    {
        $body = (string)json_encode([[
            'event' => 'delivered',
            'timestamp' => 1737000000,
            'x-grav-send-id' => 41,
        ]]);

        $event = (new SendGridReports())->parse(new WebhookRequest(body: $body))->events[0];

        self::assertSame('41', $event->sendId);
    }

    /**
     * An address arriving as `Name <addr>` is the same person as `addr`.
     *
     * A store keys its suppression list on the address, so one spelling has to
     * win.
     */
    public function testAnAddressWithADisplayNameIsNormalised(): void
    {
        $body = (string)json_encode([[
            'event' => 'delivered',
            'timestamp' => 1737000000,
            'email' => 'Jane Smith <Jane@Example.COM>',
        ]]);

        $event = (new SendGridReports())->parse(new WebhookRequest(body: $body))->events[0];

        self::assertSame('jane@example.com', $event->email);
    }

    /** A batch far larger than anything SendGrid sends is cut off rather than walked. */
    public function testAnAbsurdlyLargeBatchIsCappedRatherThanWalked(): void
    {
        $rows = array_fill(0, SendGridReports::MAX_EVENTS + 50, [
            'event' => 'delivered',
            'timestamp' => 1737000000,
            'email' => 'a@example.com',
        ]);

        $payload = (new SendGridReports())->parse(new WebhookRequest(body: (string)json_encode($rows)));

        self::assertCount(SendGridReports::MAX_EVENTS, $payload->events);
    }

    /** A timestamp from before 2000 is no timestamp; the caller stamps its own. */
    public function testAnImpossibleTimestampIsTreatedAsNoTimestamp(): void
    {
        $body = (string)json_encode([['event' => 'open', 'timestamp' => 0, 'email' => 'a@example.com']]);

        self::assertSame(0, (new SendGridReports())->parse(new WebhookRequest(body: $body))->events[0]->at);
    }

    /** The events this provider says it can report are exactly the ones it maps. */
    public function testItReportsTheSixEventsItCanRead(): void
    {
        self::assertSame([
            Event::DELIVERED,
            Event::BOUNCED,
            Event::DROPPED,
            Event::COMPLAINED,
            Event::OPENED,
            Event::CLICKED,
        ], (new SendGridReports())->events());

        foreach ((new SendGridReports())->events() as $type) {
            self::assertContains($type, Event::TYPES, "{$type} is not one of the contract's words");
        }
    }

    // ------------------------------------------------------------- internals

    private static function request(string $fixture): WebhookRequest
    {
        return new WebhookRequest(
            headers: ['content-type' => 'application/json'],
            body: self::body($fixture),
        );
    }

    public static function body(string $fixture): string
    {
        $path = \dirname(__DIR__, 2) . "/fixtures/webhooks/sendgrid/{$fixture}.json";
        self::assertFileExists($path, "there is no documented sample at {$path}");

        return (string)file_get_contents($path);
    }
}
