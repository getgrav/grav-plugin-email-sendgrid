<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSendgrid\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\WebhookRequest;
use Grav\Plugin\EmailSendgrid\Provider\SendGridReports;
use PHPUnit\Framework\TestCase;

/**
 * A forged event is refused, and this is the test that says so.
 *
 * Every signature here is computed for real, against an EC key pair this test
 * generates, rather than pasted from a documentation sample. A test built on a
 * fixed signature can only ever check that one string equals another; a test
 * that signs and then verifies checks that the thing being signed is the thing
 * SendGrid signs, which is where this goes wrong when it goes wrong.
 *
 * The forged cases are the point. A verifier that accepted everything would
 * pass a test that only fed it genuine payloads, and a store whose verification
 * key was wrong would then be acting on anything anybody posted at a URL they
 * had guessed.
 *
 * Moved from the KahunaCart Newsletter add-on, where this parser used to live.
 */
final class SendGridVerifyTest extends TestCase
{
    public function testAGenuineSignatureIsAccepted(): void
    {
        [$private, $public] = self::keysOrSkip();

        $body = '[{"event":"bounce","email":"a@example.com"}]';
        $timestamp = '1737000000';

        $verdict = (new SendGridReports())->verify(
            self::request($body, self::headers($timestamp, self::sign($private, $timestamp . $body))),
            ['public_key' => $public]
        );

        self::assertTrue($verdict->ok, $verdict->reason);
        self::assertTrue($verdict->signed, 'SendGrid signs, so this is a verified verdict rather than an unsigned one');
        self::assertNull($verdict->confirmUrl);
    }

    /**
     * The body changed after it was signed.
     *
     * SendGrid's own warning is that the raw bytes are what is signed, so a
     * re-encoded body would fail here too — which is why `WebhookRequest::$body`
     * is never touched.
     */
    public function testABodyChangedAfterSigningIsRefused(): void
    {
        [$private, $public] = self::keysOrSkip();

        $body = '[{"event":"bounce","email":"a@example.com"}]';
        $timestamp = '1737000000';
        $headers = self::headers($timestamp, self::sign($private, $timestamp . $body));

        $tampered = '[{"event":"delivered","email":"a@example.com"}]';
        $verdict = (new SendGridReports())->verify(self::request($tampered, $headers), ['public_key' => $public]);

        self::assertFalse($verdict->ok);
        self::assertNotSame('', $verdict->reason, 'the log needs to say which check failed');
    }

    /** The timestamp changed, which is what a replay would do. */
    public function testAReplayedTimestampIsRefused(): void
    {
        [$private, $public] = self::keysOrSkip();

        $body = '[{"event":"bounce","email":"a@example.com"}]';
        $timestamp = '1737000000';

        $replayed = self::headers('1737009999', self::sign($private, $timestamp . $body));

        self::assertFalse((new SendGridReports())->verify(self::request($body, $replayed), ['public_key' => $public])->ok);
    }

    /**
     * A store with no key on file refuses everything, rather than accepting
     * everything.
     *
     * This is the failure that matters most: a merchant who set up the webhook
     * and never pasted the key back has a signed webhook and an empty field,
     * and the wrong answer here would be a public address anybody can post
     * bounces to.
     */
    public function testNoKeyConfiguredRefusesRatherThanAccepts(): void
    {
        [$private] = self::keysOrSkip();

        $body = '[{"event":"bounce"}]';
        $headers = self::headers('1737000000', self::sign($private, '1737000000' . $body));

        foreach ([[], ['public_key' => ''], ['public_key' => '   ']] as $config) {
            $verdict = (new SendGridReports())->verify(self::request($body, $headers), $config);

            self::assertFalse($verdict->ok);
            self::assertStringContainsString('no SendGrid verification key', $verdict->reason);
        }
    }

    /** A key that is not a key is a refusal in the log rather than a PHP warning in it. */
    public function testAKeyThatIsNotAKeyIsRefusedQuietly(): void
    {
        [$private] = self::keysOrSkip();

        $body = '[{"event":"bounce"}]';
        $headers = self::headers('1737000000', self::sign($private, '1737000000' . $body));

        foreach (['not a key', 'bm90IGEga2V5', '-----BEGIN PUBLIC KEY-----\nnope\n-----END PUBLIC KEY-----'] as $key) {
            $verdict = (new SendGridReports())->verify(self::request($body, $headers), ['public_key' => $key]);

            self::assertFalse($verdict->ok, $key);
        }
    }

    /** A request with no signature headers at all is refused before anything is decoded. */
    public function testMissingSignatureHeadersAreRefused(): void
    {
        [, $public] = self::keysOrSkip();

        $reports = new SendGridReports();
        $body = '[{"event":"bounce"}]';

        self::assertFalse($reports->verify(self::request($body, []), ['public_key' => $public])->ok);
        self::assertFalse($reports->verify(
            self::request($body, [SendGridReports::TIMESTAMP_HEADER => '1737000000']),
            ['public_key' => $public]
        )->ok);
        self::assertFalse($reports->verify(
            self::request($body, [SendGridReports::SIGNATURE_HEADER => 'abcd']),
            ['public_key' => $public]
        )->ok);
    }

    /** A signature that is not base64 is refused before OpenSSL is handed anything. */
    public function testASignatureThatIsNotBase64IsRefused(): void
    {
        [, $public] = self::keysOrSkip();

        $verdict = (new SendGridReports())->verify(
            self::request('[{"event":"bounce"}]', self::headers('1737000000', '!!! not base64 !!!')),
            ['public_key' => $public]
        );

        self::assertFalse($verdict->ok);
        self::assertStringContainsString('base64', $verdict->reason);
    }

    /** A merchant who pasted the whole PEM is not punished for being thorough. */
    public function testAKeyThatAlreadyHasItsPemWrapperIsAccepted(): void
    {
        [$private, $public] = self::keysOrSkip();

        $body = '[{"event":"open"}]';
        $timestamp = '1737000000';

        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($public, 64, "\n") . "-----END PUBLIC KEY-----\n";

        $verdict = (new SendGridReports())->verify(
            self::request($body, self::headers($timestamp, self::sign($private, $timestamp . $body))),
            ['public_key' => $pem]
        );

        self::assertTrue($verdict->ok, $verdict->reason);
    }

    /** The header names are matched however a proxy spelled them. */
    public function testTheSignatureHeadersAreMatchedCaseInsensitively(): void
    {
        [$private, $public] = self::keysOrSkip();

        $body = '[{"event":"open"}]';
        $timestamp = '1737000000';

        $request = new WebhookRequest(headers: [
            'x-twilio-email-event-webhook-timestamp' => $timestamp,
            'x-twilio-email-event-webhook-signature' => self::sign($private, $timestamp . $body),
        ], body: $body);

        self::assertTrue((new SendGridReports())->verify($request, ['public_key' => $public])->ok);
    }

    /** The key this provider asks for is the one the blueprint has a field for. */
    public function testItAsksForTheVerificationKeyByName(): void
    {
        self::assertSame(['public_key'], (new SendGridReports())->verificationKeys());
    }

    // ------------------------------------------------------------- internals

    /** @return array{0: \OpenSSLAsymmetricKey, 1: string} */
    private static function keysOrSkip(): array
    {
        $keys = self::ecdsaKeys();

        if ($keys === null) {
            self::markTestSkipped('this build of PHP has no OpenSSL EC support');
        }

        return $keys;
    }

    private static function sign(\OpenSSLAsymmetricKey $private, string $payload): string
    {
        $signature = '';
        openssl_sign($payload, $signature, $private, \OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    /** @return array<string, string> */
    private static function headers(string $timestamp, string $signature): array
    {
        return [
            SendGridReports::TIMESTAMP_HEADER => $timestamp,
            SendGridReports::SIGNATURE_HEADER => $signature,
        ];
    }

    /** @param array<string, string> $headers */
    private static function request(string $body, array $headers): WebhookRequest
    {
        return new WebhookRequest(
            method: 'POST',
            path: '/newsletter/webhook/sendgrid/abcd',
            headers: $headers,
            body: $body,
            remoteAddress: '203.0.113.7',
        );
    }

    /**
     * An EC key pair, with the public half as the bare base64 SendGrid's
     * dashboard shows.
     *
     * @return array{0: \OpenSSLAsymmetricKey, 1: string}|null
     */
    private static function ecdsaKeys(): ?array
    {
        if (!\function_exists('openssl_pkey_new')) {
            return null;
        }

        $key = @openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            return null;
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false || !isset($details['key'])) {
            return null;
        }

        $bare = preg_replace('/-----[A-Z ]+-----|\s+/', '', (string)$details['key']) ?? '';

        return [$key, $bare];
    }
}
