<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSendgrid\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\Inbound\InboundCapable;
use Grav\Plugin\Email\Providers\Inbound\InboundGateway;
use Grav\Plugin\Email\Providers\Inbound\InboundMessage;
use Grav\Plugin\Email\Providers\Inbound\InboundReference;
use Grav\Plugin\Email\Providers\Inbound\InboundRequest;
use Grav\Plugin\Email\Providers\Inbound\InboundUpload;
use Grav\Plugin\Email\Providers\ProviderRegistry;
use Grav\Plugin\EmailSendgrid\Provider\SendGridInbound;
use Grav\Plugin\EmailSendgrid\Provider\SendGridInboundProvider;
use Grav\Plugin\EmailSendgrid\Provider\SendGridProvider;
use Grav\Plugin\EmailSendgrid\Provider\SendGridReports;
use PHPUnit\Framework\TestCase;

/**
 * SendGrid's Inbound Parse, read from fixtures built on the documented fields.
 *
 * Every mode SendGrid posts in is here: the parsed form with an inline image
 * and an attachment, the raw form with the whole message in `email`, bodies in
 * legacy charsets, and a raw multipart body signed by a security policy, which
 * is the only case where a signature can be checked from PHP at all.
 */
final class SendGridInboundTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/inbound/sendgrid/';

    public function testTheProviderIsInboundCapableAndTheGatewayFindsIt(): void
    {
        self::assertNotInstanceOf(InboundCapable::class, new SendGridProvider(), 'the base class never names the interface');

        $provider = new SendGridInboundProvider();
        self::assertInstanceOf(SendGridProvider::class, $provider);
        self::assertInstanceOf(InboundCapable::class, $provider);
        self::assertInstanceOf(SendGridInbound::class, $provider->inbound());
        self::assertSame($provider->inbound(), $provider->inbound());

        $registry = new ProviderRegistry();
        $registry->add($provider);
        $gateway = new InboundGateway(null, $registry);

        self::assertInstanceOf(SendGridInbound::class, $gateway->receiver('sendgrid'));
    }

    public function testItDescribesItself(): void
    {
        $receiver = new SendGridInbound();

        self::assertSame('sendgrid', $receiver->key());
        self::assertSame('SendGrid', $receiver->label());
        self::assertSame(['inbound_public_key', 'inbound_username', 'inbound_password'], $receiver->verificationKeys());
        self::assertGreaterThanOrEqual(30 * 1000 * 1000, $receiver->maxBytes());
    }

    public function testTheInstructionsNameTheUrlTheMxAndTheRawToggle(): void
    {
        $text = (new SendGridInbound())->instructions('https://example.com/_helpdesk/inbound/sendgrid/secret');

        self::assertStringContainsString('https://example.com/_helpdesk/inbound/sendgrid/secret', $text);
        self::assertStringContainsString('mx.sendgrid.net', $text);
        self::assertStringContainsString('POST the raw, full MIME message', $text);
        self::assertStringContainsString('Inbound Parse', $text);
    }

    // ------------------------------------------------------------ verify

    public function testWithNothingConfiguredItPassesAsUnsigned(): void
    {
        $verdict = (new SendGridInbound())->verify(self::fixture('raw.json'), []);

        self::assertTrue($verdict->ok);
        self::assertFalse($verdict->signed, 'the URL secret is the whole check, and the admin UI says so');
    }

    public function testBasicAuthIsCheckedWhenConfigured(): void
    {
        $config = ['inbound_username' => 'sendgrid', 'inbound_password' => 'correct horse'];
        $good = 'Basic ' . base64_encode('sendgrid:correct horse');

        $ok = (new SendGridInbound())->verify(self::fixture('raw.json', ['authorization' => $good]), $config);
        self::assertTrue($ok->ok, $ok->reason);
        self::assertFalse($ok->signed);

        $wrong = (new SendGridInbound())->verify(
            self::fixture('raw.json', ['authorization' => 'Basic ' . base64_encode('sendgrid:wrong')]),
            $config
        );
        self::assertFalse($wrong->ok);

        $missing = (new SendGridInbound())->verify(self::fixture('raw.json'), $config);
        self::assertFalse($missing->ok);
    }

    public function testHalfABasicAuthPairIsRefusedRatherThanIgnored(): void
    {
        $verdict = (new SendGridInbound())->verify(self::fixture('raw.json'), ['inbound_username' => 'sendgrid']);

        self::assertFalse($verdict->ok);
        self::assertStringContainsString('both', $verdict->reason);
    }

    public function testAPublicKeyWithNoRawBodyIsRefusedAndSaysWhy(): void
    {
        [, $public] = self::keysOrSkip();

        $verdict = (new SendGridInbound())->verify(self::fixture('raw.json'), ['inbound_public_key' => $public]);

        self::assertFalse($verdict->ok);
        self::assertStringContainsString('enable_post_data_reading', $verdict->reason);
    }

    public function testASignedRawBodyIsVerifiedAndThenReadFromTheBody(): void
    {
        [$private, $public] = self::keysOrSkip();

        [$body, $contentType] = self::multipartOf('raw.json');
        $timestamp = '1758620000';
        $request = self::signed($body, $contentType, $timestamp, self::sign($private, $timestamp . $body));

        $verdict = (new SendGridInbound())->verify($request, ['inbound_public_key' => $public]);
        self::assertTrue($verdict->ok, $verdict->reason);
        self::assertTrue($verdict->signed);

        $fromBody = self::only((new SendGridInbound())->parse($request, []));
        $fromForm = self::only((new SendGridInbound())->parse(self::fixture('raw.json'), []));

        self::assertSame($fromForm->raw, $fromBody->raw);
        self::assertSame($fromForm->messageId, $fromBody->messageId);
        self::assertSame($fromForm->envelopeTo, $fromBody->envelopeTo);
        self::assertSame($fromForm->auth, $fromBody->auth);
        self::assertSame($fromForm->spamScore, $fromBody->spamScore);
    }

    public function testASignedParsedBodyKeepsItsAttachments(): void
    {
        [$private, $public] = self::keysOrSkip();

        [$body, $contentType] = self::multipartOf('parsed-with-attachments.json');
        $timestamp = '1758620000';
        $request = self::signed($body, $contentType, $timestamp, self::sign($private, $timestamp . $body));

        self::assertTrue((new SendGridInbound())->verify($request, ['inbound_public_key' => $public])->ok);

        $message = self::only((new SendGridInbound())->parse($request, []));
        self::assertCount(2, $message->attachments);
        self::assertSame((string)file_get_contents(self::FIXTURES . 'attachment1.png'), $message->attachments[0]->bytes());
        self::assertSame('ii_lf8x0abc1', $message->attachments[0]->contentId);
    }

    public function testABodyChangedAfterSigningIsRefused(): void
    {
        [$private, $public] = self::keysOrSkip();

        [$body, $contentType] = self::multipartOf('raw.json');
        $timestamp = '1758620000';
        $signature = self::sign($private, $timestamp . $body);

        $tampered = str_replace('Screen flickers', 'Screen flickered', $body);
        $verdict = (new SendGridInbound())->verify(
            self::signed($tampered, $contentType, $timestamp, $signature),
            ['inbound_public_key' => $public]
        );

        self::assertFalse($verdict->ok);
    }

    public function testAPublicKeyAndNoSignatureHeadersIsRefused(): void
    {
        [, $public] = self::keysOrSkip();

        [$body, $contentType] = self::multipartOf('raw.json');
        $request = new InboundRequest(headers: ['content-type' => $contentType], body: $body);

        self::assertFalse((new SendGridInbound())->verify($request, ['inbound_public_key' => $public])->ok);
    }

    public function testTheGatewayRefusesAnythingOverTheLimitBeforeVerifying(): void
    {
        $registry = new ProviderRegistry();
        $registry->add(new SendGridInboundProvider());
        $gateway = new InboundGateway(null, $registry);

        $request = self::fixture('raw.json', ['content-length' => (string)(SendGridInbound::MAX_BYTES + 1)]);
        self::assertSame(413, $gateway->receive('sendgrid', $request, [])->status);

        $result = $gateway->receive('sendgrid', self::fixture('raw.json'), []);
        self::assertSame(200, $result->status);
        self::assertCount(1, $result->messages());
    }

    // ------------------------------------------------------------ raw mode

    public function testRawModeIsReadFromTheWholeMessage(): void
    {
        $message = self::only((new SendGridInbound())->parse(self::fixture('raw.json'), []));

        self::assertSame((string)file_get_contents(self::FIXTURES . 'raw.eml'), $message->raw);
        self::assertSame('sendgrid', $message->receiver);
        self::assertSame('5e3b7d2a-9f11-4c8b-a0d1-7e2c3b4a5f60@example.com', $message->messageId);
        self::assertSame('hd.1003.dd44@helpdesk.example.com', $message->inReplyTo);
        self::assertSame('kim@example.com', $message->from->email);
        self::assertSame('Re: [#1003] Screen flickers', $message->subject);
        self::assertSame(['support+t41xq@reply.example.com'], $message->envelopeTo, 'the plus address lives in the envelope');
        self::assertSame('kim@example.com', $message->envelopeFrom);
        self::assertSame('pass', $message->auth['spf']);
        self::assertSame('pass', $message->auth['dkim'], 'any passing signature is a pass');
        self::assertSame(-0.4, $message->spamScore);
        self::assertNotEmpty($message->attachments);
        self::assertTrue($message->attachments[0]->inline);
    }

    public function testRawModeAlsoWorksWhenTheEmailFieldArrivesAsAFile(): void
    {
        $fixture = self::load('raw.json');
        $fields = $fixture['fields'];
        unset($fields['email']);

        $request = new InboundRequest(
            headers: ['content-type' => 'multipart/form-data; boundary=x'],
            parsedBody: $fields,
            files: [new InboundUpload('email', 'email.eml', 'message/rfc822', (int)filesize(self::FIXTURES . 'raw.eml'), self::FIXTURES . 'raw.eml')],
        );

        $message = self::only((new SendGridInbound())->parse($request, []));
        self::assertSame('5e3b7d2a-9f11-4c8b-a0d1-7e2c3b4a5f60@example.com', $message->messageId);
    }

    // ------------------------------------------------------------ parsed mode

    public function testParsedModeIsReadFromTheHeadersAndTheFields(): void
    {
        $message = self::only((new SendGridInbound())->parse(self::fixture('parsed-with-attachments.json'), []));

        self::assertNull($message->raw, 'SendGrid kept the raw message; there are no raw bytes');
        self::assertSame('sendgrid', $message->receiver);
        self::assertSame('caf=r7x+yk2qm0a@mail.gmail.com', $message->messageId);
        self::assertSame('hd.1042.aa11@helpdesk.example.com', $message->inReplyTo);
        self::assertSame(['hd.1041.ff00@helpdesk.example.com', 'hd.1042.aa11@helpdesk.example.com'], $message->references);
        self::assertSame('renee@example.net', $message->from->email);
        self::assertSame('Renée Dubois', $message->from->name);
        self::assertSame('support@reply.example.com', $message->to[0]->email);
        self::assertSame('ops@example.net', $message->cc[0]->email);
        self::assertSame('Re: [#1042] Café menu printer', $message->subject);
        self::assertSame(['support+t8f2k@reply.example.com'], $message->envelopeTo);
        self::assertSame('renee@example.net', $message->envelopeFrom);
        self::assertStringStartsWith('The printer prints the menu twice.', (string)$message->text);
        self::assertStringContainsString('cid:ii_lf8x0abc1', (string)$message->html);
        self::assertSame(['spf' => 'pass', 'dkim' => 'pass'], $message->auth);
        self::assertSame(0.1, $message->spamScore);
        self::assertSame('multipart/mixed', $message->contentType);
        self::assertNotNull($message->date);
        self::assertSame('Wed, 23 Sep 2026 12:12:04 +0200', $message->header('Date'));
    }

    public function testParsedAttachmentsKeepTheirNamesTypesAndContentIds(): void
    {
        $message = self::only((new SendGridInbound())->parse(self::fixture('parsed-with-attachments.json'), []));

        self::assertCount(2, $message->attachments);
        [$image, $pdf] = $message->attachments;

        self::assertSame('photo.png', $image->filename);
        self::assertSame('image/png', $image->contentType);
        self::assertSame('ii_lf8x0abc1', $image->contentId);
        self::assertTrue($image->inline);
        self::assertSame((string)file_get_contents(self::FIXTURES . 'attachment1.png'), $image->bytes());
        self::assertSame(filesize(self::FIXTURES . 'attachment1.png'), $image->size);

        self::assertSame('menu.pdf', $pdf->filename, 'directories in a sender filename are dropped');
        self::assertSame('application/pdf', $pdf->contentType);
        self::assertNull($pdf->contentId);
        self::assertFalse($pdf->inline);
    }

    public function testParsedBodiesInLegacyCharsetsAreConvertedToUtf8(): void
    {
        $message = self::only((new SendGridInbound())->parse(self::fixture('parsed-charsets.json'), []));

        self::assertSame("Grüße aus München, die Bestellung ist fällig.\n", $message->text);
        self::assertSame("<p>Grüße aus München – “bitte” prüfen.</p>\n", $message->html);
        self::assertSame('München Bestellung', $message->subject);
        self::assertSame('Jürgen Weiß', $message->from->name);
        self::assertSame(['spf' => 'softfail', 'dkim' => 'none'], $message->auth);
        self::assertSame([], $message->attachments);
    }

    public function testDkimWithNoPassingSignatureIsTheFirstResult(): void
    {
        $fixture = self::load('raw.json');
        $fields = $fixture['fields'];
        $fields['email'] = (string)file_get_contents(self::FIXTURES . 'raw.eml');
        $fields['dkim'] = '{@example.com : fail, @mailer.example.org : neutral}';

        $message = self::only((new SendGridInbound())->parse(new InboundRequest(parsedBody: $fields), []));

        self::assertSame('fail', $message->auth['dkim']);
    }

    // ------------------------------------------------------------ unreadable

    public function testAnEmptyRequestIsUnreadable(): void
    {
        $payload = (new SendGridInbound())->parse(new InboundRequest(), []);

        self::assertTrue($payload->unreadable);
        self::assertSame([], $payload->items);
    }

    public function testAFormWithNoMessageInItIsUnreadable(): void
    {
        $payload = (new SendGridInbound())->parse(new InboundRequest(parsedBody: ['sender_ip' => '1.2.3.4']), []);

        self::assertTrue($payload->unreadable);
    }

    public function testGarbageInTheJsonFieldsIsIgnoredRatherThanThrown(): void
    {
        $payload = (new SendGridInbound())->parse(new InboundRequest(parsedBody: [
            'from' => 'a@example.com',
            'text' => 'hello',
            'envelope' => '{not json',
            'charsets' => '[1,2',
            'attachment-info' => '"a string"',
            'content-ids' => 'null',
            'spam_score' => 'high',
        ]), []);

        self::assertFalse($payload->unreadable, $payload->note);
        $message = $payload->items[0];
        self::assertInstanceOf(InboundMessage::class, $message);
        self::assertSame('a@example.com', $message->from->email);
        self::assertSame('hello', $message->text);
        self::assertNull($message->spamScore);
    }

    public function testFetchIsNeverNeeded(): void
    {
        $this->expectException(\LogicException::class);
        (new SendGridInbound())->fetch(new InboundReference('sendgrid', 'x'), []);
    }

    // ------------------------------------------------------------ helpers

    /** @return array{fields: array<string, string>, files?: array<string, array{filename: string, type: string, file: string}>, _encode?: array<string, string>} */
    private static function load(string $name): array
    {
        $fixture = json_decode((string)file_get_contents(self::FIXTURES . $name), true, 32, \JSON_THROW_ON_ERROR);

        foreach ($fixture['fields'] as $field => $value) {
            if (\is_string($value) && str_starts_with($value, '@')) {
                $fixture['fields'][$field] = (string)file_get_contents(self::FIXTURES . substr($value, 1));
            }
        }
        foreach ($fixture['_encode'] ?? [] as $field => $charset) {
            $fixture['fields'][$field] = mb_convert_encoding($fixture['fields'][$field], $charset, 'UTF-8');
        }

        return $fixture;
    }

    /**
     * A fixture as PHP hands it over: fields in `parsedBody`, files as uploads,
     * and no body, because PHP never keeps one for multipart.
     *
     * @param array<string, string> $headers
     */
    private static function fixture(string $name, array $headers = []): InboundRequest
    {
        $fixture = self::load($name);

        $files = [];
        foreach ($fixture['files'] ?? [] as $field => $file) {
            $path = self::FIXTURES . $file['file'];
            $files[] = new InboundUpload($field, $file['filename'], $file['type'], (int)filesize($path), $path);
        }

        return new InboundRequest(
            headers: $headers + ['content-type' => 'multipart/form-data; boundary=xYzZY'],
            parsedBody: $fixture['fields'],
            files: $files,
        );
    }

    /**
     * The same fixture as the raw multipart body SendGrid sends.
     *
     * @return array{0: string, 1: string}
     */
    private static function multipartOf(string $name): array
    {
        $fixture = self::load($name);
        $boundary = 'xYzZY';
        $body = '';

        foreach ($fixture['fields'] as $field => $value) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$field}\"\r\n\r\n{$value}\r\n";
        }
        foreach ($fixture['files'] ?? [] as $field => $file) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$field}\"; filename=\"{$file['filename']}\"\r\n"
                . "Content-Type: {$file['type']}\r\n\r\n"
                . file_get_contents(self::FIXTURES . $file['file']) . "\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        return [$body, 'multipart/form-data; boundary=' . $boundary];
    }

    private static function signed(string $body, string $contentType, string $timestamp, string $signature): InboundRequest
    {
        return new InboundRequest(
            headers: [
                'content-type' => $contentType,
                SendGridReports::SIGNATURE_HEADER => $signature,
                SendGridReports::TIMESTAMP_HEADER => $timestamp,
            ],
            body: $body,
        );
    }

    private static function only(\Grav\Plugin\Email\Providers\Inbound\InboundPayload $payload): InboundMessage
    {
        self::assertFalse($payload->unreadable, $payload->note);
        self::assertCount(1, $payload->items);
        self::assertInstanceOf(InboundMessage::class, $payload->items[0]);

        return $payload->items[0];
    }

    /** @return array{0: \OpenSSLAsymmetricKey, 1: string} */
    private static function keysOrSkip(): array
    {
        $key = @openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            self::markTestSkipped('this PHP cannot generate an EC key');
        }
        $details = openssl_pkey_get_details($key);

        return [$key, (string)$details['key']];
    }

    private static function sign(\OpenSSLAsymmetricKey $private, string $payload): string
    {
        openssl_sign($payload, $signature, $private, \OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }
}
