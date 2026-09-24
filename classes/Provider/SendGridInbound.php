<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSendgrid\Provider;

use Grav\Plugin\Email\Providers\Inbound\Address;
use Grav\Plugin\Email\Providers\Inbound\InboundAttachment;
use Grav\Plugin\Email\Providers\Inbound\InboundMessage;
use Grav\Plugin\Email\Providers\Inbound\InboundPayload;
use Grav\Plugin\Email\Providers\Inbound\InboundReceiver;
use Grav\Plugin\Email\Providers\Inbound\InboundReference;
use Grav\Plugin\Email\Providers\Inbound\InboundRequest;
use Grav\Plugin\Email\Providers\Inbound\MimeParser;
use Grav\Plugin\Email\Providers\Verdict;
use Grav\Plugin\Email\Providers\WebhookRequest;

/**
 * SendGrid's Inbound Parse webhook, read.
 *
 * Documentation: `twilio.com/docs/sendgrid/for-developers/parsing-email/` —
 * `setting-up-the-inbound-parse-webhook`, `inbound-email` and
 * `securing-your-parse-webhooks` — plus the API reference for
 * `settings-inbound-parse/create-a-parse-webhook-security-policy`. Read
 * 2026-09-23.
 *
 * ## Two ways SendGrid posts a message
 *
 * Both are `multipart/form-data`, one request per message.
 *
 * - **Raw**, when "POST the raw, full MIME message" is ticked on the host's
 *   settings: the whole message, byte for byte, in one `email` field, beside
 *   `envelope`, `SPF`, `dkim`, `spam_score` and friends. This is the one to
 *   use. The consumer gets the real message to keep, and every header,
 *   attachment and charset comes out of the same MIME parser as every other
 *   receiver's mail.
 * - **Parsed**, the default: SendGrid takes the message apart. `headers` is the
 *   raw header block, `text` and `html` are the bodies, `from`, `to`, `cc` and
 *   `subject` are header values, and each attachment is a file upload named
 *   `attachment1`, `attachment2`, … described by the `attachment-info` JSON
 *   (`filename`, `type`, `content-id`) and indexed by `content-ids`
 *   (`{"ii_abc": "attachment1"}`). The headers are run through the MIME parser
 *   on their own, so the ids and addresses are read exactly as they are for
 *   raw mail, and the bodies and attachments are laid over the result.
 *
 * `charsets` is a JSON map of field name to the charset of that field's value,
 * e.g. `{"to":"UTF-8","subject":"UTF-8","text":"iso-8859-1"}`. SendGrid decodes
 * the address and subject headers to UTF-8 itself, but the bodies arrive in
 * whatever charset the sender wrote them in, so every field is converted by
 * the charset named here. A parsed message has no raw bytes, so its `raw` is
 * null, and its attachments are the upload files PHP wrote for this request,
 * which PHP deletes when the request ends: a consumer copies them while it is
 * still handling the request.
 *
 * ## What SendGrid says about the sender
 *
 * - `SPF` is the SPF result, `pass`, `softfail`, `fail` and so on.
 * - `dkim` is one result per signature, `{@example.com : pass}`, several in
 *   one set of braces when there are several signatures, or `none`. Any pass is
 *   a pass, which is the same rule the MIME parser applies to
 *   `Authentication-Results`.
 * - There is no DMARC result.
 * - `spam_score` is SpamAssassin's score, sent only when "Check incoming
 *   emails for spam" is ticked.
 * - `envelope` is `{"to": ["support+t8f2k@example.com"], "from": "a@b.c"}`, the
 *   SMTP envelope, which is where a plus address survives.
 *
 * ## How a request is authenticated
 *
 * For years Inbound Parse signed nothing. It now has **security policies**: a
 * policy with signature verification makes SendGrid sign each post with ECDSA
 * over the timestamp and the raw request body, in the same two headers and the
 * same scheme as the Event Webhook
 * (`X-Twilio-Email-Event-Webhook-Signature`, `…-Timestamp`), so the check is
 * {@see SendGridReports::verify()} itself. The policy's public key goes in
 * `inbound_public_key`.
 *
 * There is a catch that is PHP's rather than SendGrid's. The signature covers
 * the multipart body exactly as it was sent, and PHP does not keep that body
 * for `multipart/form-data`: it splits it into `$_POST` and `$_FILES` and
 * `php://input` is empty. So a signature can only be checked where PHP has been
 * told to leave the body alone (`enable_post_data_reading = Off` for the
 * webhook's path). With a public key configured and no raw body to check, the
 * request is refused and the log says why, rather than passed unchecked. When
 * the raw body is there, this class reads the multipart form out of it itself.
 *
 * A policy can use OAuth instead, where SendGrid fetches a token from a server
 * the site owner runs and sends it as a bearer token. Checking that token means
 * asking that server, which is network I/O a receiver may not do, so it is not
 * supported here.
 *
 * Without a signature the receiver authenticates by the consumer's secret URL
 * alone, plus HTTP basic auth when `inbound_username` and `inbound_password`
 * are set, and answers {@see Verdict::unsigned()}: the admin screen says
 * "authenticated by secret URL".
 *
 * ## Answer fast
 *
 * SendGrid retries a post that answers 5xx and drops the message after three
 * days. A consumer answers 200 as soon as the message is stored.
 */
final class SendGridInbound implements InboundReceiver
{
    public const KEY = 'sendgrid';

    /** The config key holding the security policy's public key. */
    public const PUBLIC_KEY = 'inbound_public_key';

    /** Optional HTTP basic auth pair. */
    public const USERNAME = 'inbound_username';
    public const PASSWORD = 'inbound_password';

    /**
     * SendGrid's limit is 30 MB for the message and its attachments together.
     * The form around it adds a little (the envelope, the headers again in
     * parsed mode), so the ceiling is 32 MiB.
     */
    public const MAX_BYTES = 32 * 1024 * 1024;

    /** More form parts than this in a raw body is not a message SendGrid sent. */
    private const MAX_PARTS = 1000;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'SendGrid';
    }

    public function verificationKeys(): array
    {
        return [self::PUBLIC_KEY, self::USERNAME, self::PASSWORD];
    }

    public function maxBytes(): int
    {
        return self::MAX_BYTES;
    }

    public function verify(InboundRequest $request, array $config): Verdict
    {
        $username = self::setting($config, self::USERNAME);
        $password = self::setting($config, self::PASSWORD);

        if ($username !== '' || $password !== '') {
            if ($username === '' || $password === '') {
                return Verdict::refused('only one of the SendGrid inbound username and password is set; set both or neither');
            }

            $expected = 'Basic ' . base64_encode($username . ':' . $password);
            if (!hash_equals($expected, trim($request->header('authorization')))) {
                return Verdict::refused('the basic auth credentials did not match');
            }
        }

        $key = self::setting($config, self::PUBLIC_KEY);
        if ($key === '') {
            return Verdict::unsigned();
        }

        if ($request->body === '') {
            return Verdict::refused(
                'a SendGrid inbound public key is set, but PHP had already split the multipart form, so the raw body '
                . 'SendGrid signed is gone and the signature cannot be checked. Either set enable_post_data_reading = Off '
                . 'for the webhook path, or remove the signature from the Parse security policy and the key from here'
            );
        }

        return (new SendGridReports())->verify(
            new WebhookRequest($request->method, $request->path, $request->query, $request->headers, $request->body, $request->remoteAddress),
            [SendGridReports::KEY => $key],
        );
    }

    public function parse(InboundRequest $request, array $config): InboundPayload
    {
        try {
            $form = self::form($request);
            if ($form === null) {
                return InboundPayload::unreadable('the request was not a SendGrid Inbound Parse form post');
            }

            [$fields, $files] = $form;
            $charsets = self::json($fields['charsets'] ?? '');
            $overrides = self::overrides($fields);

            $raw = $fields['email'] ?? (isset($files['email']) ? self::bytes($files['email']) : null);
            if ($raw !== null && trim($raw) !== '') {
                return InboundPayload::of([self::finish(InboundMessage::fromMime($raw, self::KEY), $overrides)]);
            }

            if (!isset($fields['headers']) && !isset($fields['from']) && !isset($fields['text']) && !isset($fields['html'])) {
                return InboundPayload::unreadable('the form carried neither a raw email field nor parsed message fields');
            }

            return InboundPayload::of([self::parsed($fields, $files, $charsets, $overrides)]);
        } catch (\Throwable $e) {
            return InboundPayload::unreadable('the SendGrid form could not be read: ' . $e->getMessage());
        }
    }

    /**
     * Never called: SendGrid always posts the whole message.
     *
     * @throws \LogicException
     */
    public function fetch(InboundReference $ref, array $config): InboundMessage
    {
        throw new \LogicException('SendGrid posts whole messages; there is nothing to fetch.');
    }

    public function instructions(string $webhookUrl): string
    {
        return 'Pick the domain or subdomain that will receive mail (a subdomain such as reply.example.com keeps your normal mailbox untouched) '
            . 'and authenticate it in SendGrid under Settings, Sender Authentication. At your DNS host, add an MX record for that name '
            . 'pointing to mx.sendgrid.net with priority 10. In SendGrid, go to Settings, Inbound Parse, and press Add Host & URL. '
            . 'Choose the receiving domain, paste ' . $webhookUrl . ' as the Destination URL, and tick "POST the raw, full MIME message" '
            . '(the whole message is kept that way, and attachments arrive intact). Tick "Check incoming emails for spam" too if you want a spam score. '
            . 'SendGrid does not sign Parse posts unless you attach a security policy with signature verification to the host through its API; '
            . 'then paste the policy\'s public key into Inbound public key in the SendGrid plugin settings. Signed posts can only be checked '
            . 'where PHP keeps the raw request body (enable_post_data_reading = Off for this address); without that, leave the key empty, '
            . 'and the long secret in the address is what protects it. Messages are limited to 30 MB.';
    }

    // ------------------------------------------------------------- internals

    /**
     * The form fields and files, from PHP's parsed form or, where PHP kept the
     * raw body, from the body itself.
     *
     * @return array{0: array<string, string>, 1: array<string, array{filename: string, type: string, size: int, content: ?string, path: ?string}>}|null
     */
    private static function form(InboundRequest $request): ?array
    {
        $fields = [];
        $files = [];

        if ($request->parsedBody !== [] || $request->files !== []) {
            foreach ($request->parsedBody as $name => $value) {
                if (is_scalar($value)) {
                    $fields[(string)$name] = (string)$value;
                }
            }
            foreach ($request->files as $upload) {
                if ($upload->error !== 0) {
                    continue;
                }
                $files[$upload->field] = [
                    'filename' => $upload->filename,
                    'type' => $upload->type,
                    'size' => max(0, $upload->size),
                    'content' => null,
                    'path' => $upload->tmpPath,
                ];
            }

            return [$fields, $files];
        }

        if ($request->body === '') {
            return null;
        }

        $type = $request->contentType();
        if ($type === 'application/x-www-form-urlencoded') {
            parse_str($request->body, $parsed);
            foreach ($parsed as $name => $value) {
                if (is_scalar($value)) {
                    $fields[(string)$name] = (string)$value;
                }
            }

            return [$fields, []];
        }

        if ($type !== 'multipart/form-data'
            || !preg_match('/boundary\s*=\s*(?:"([^"]+)"|([^;\s]+))/i', $request->header('content-type'), $m)) {
            return null;
        }

        return self::multipart($request->body, $m[1] !== '' ? $m[1] : $m[2]);
    }

    /**
     * A `multipart/form-data` body split into fields and files, for the case
     * where PHP kept the raw body (so a signature over it could be checked).
     *
     * @return array{0: array<string, string>, 1: array<string, array{filename: string, type: string, size: int, content: ?string, path: ?string}>}|null
     */
    private static function multipart(string $body, string $boundary): ?array
    {
        $chunks = explode('--' . $boundary, $body);
        if (\count($chunks) < 3) {
            return null;
        }
        array_shift($chunks); // the preamble

        $fields = [];
        $files = [];
        foreach ($chunks as $index => $chunk) {
            if ($index >= self::MAX_PARTS || str_starts_with($chunk, '--')) {
                break;
            }

            $chunk = (string)preg_replace('/^\r?\n/', '', $chunk);
            if (str_ends_with($chunk, "\r\n")) {
                $chunk = substr($chunk, 0, -2);
            } elseif (str_ends_with($chunk, "\n")) {
                $chunk = substr($chunk, 0, -1);
            }

            $split = preg_split('/\r?\n\r?\n/', $chunk, 2);
            if (!\is_array($split) || \count($split) !== 2) {
                continue;
            }
            [$head, $content] = $split;

            $disposition = '';
            $partType = '';
            foreach (preg_split('/\r?\n(?![ \t])/', $head) ?: [] as $line) {
                $colon = strpos($line, ':');
                if ($colon === false) {
                    continue;
                }
                $name = strtolower(trim(substr($line, 0, $colon)));
                $value = trim(substr($line, $colon + 1));
                if ($name === 'content-disposition') {
                    $disposition = $value;
                } elseif ($name === 'content-type') {
                    $partType = $value;
                }
            }

            if (!preg_match('/\bname\s*=\s*(?:"([^"]*)"|([^;\s]+))/i', $disposition, $n)) {
                continue;
            }
            $field = $n[1] !== '' ? $n[1] : ($n[2] ?? '');

            if (preg_match('/\bfilename\s*=\s*(?:"([^"]*)"|([^;\s]+))/i', $disposition, $f)) {
                $files[$field] = [
                    'filename' => $f[1] !== '' ? $f[1] : ($f[2] ?? ''),
                    'type' => $partType,
                    'size' => \strlen($content),
                    'content' => $content,
                    'path' => null,
                ];
                continue;
            }

            $fields[$field] = $content;
        }

        return [$fields, $files];
    }

    /**
     * What SendGrid knows that the message itself does not say: the envelope,
     * the SPF and DKIM results and the spam score.
     *
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private static function overrides(array $fields): array
    {
        $overrides = [];

        $envelope = self::json($fields['envelope'] ?? '');
        $to = $envelope['to'] ?? null;
        if (\is_string($to)) {
            $to = [$to];
        }
        if (\is_array($to)) {
            $list = [];
            foreach ($to as $address) {
                if (\is_string($address) && trim($address) !== '') {
                    $list[] = trim($address, " \t<>");
                }
            }
            if ($list !== []) {
                $overrides['envelopeTo'] = $list;
            }
        }
        if (\array_key_exists('from', $envelope) && (\is_string($envelope['from']) || $envelope['from'] === null)) {
            $overrides['envelopeFrom'] = trim((string)$envelope['from'], " \t<>");
        }

        $auth = [];
        $spf = self::spf($fields['SPF'] ?? $fields['spf'] ?? '');
        if ($spf !== null) {
            $auth['spf'] = $spf;
        }
        $dkim = self::dkim($fields['dkim'] ?? '');
        if ($dkim !== null) {
            $auth['dkim'] = $dkim;
        }
        if ($auth !== []) {
            $overrides['auth'] = $auth;
        }

        $score = trim($fields['spam_score'] ?? '');
        if ($score !== '' && is_numeric($score)) {
            $overrides['spamScore'] = (float)$score;
        }

        return $overrides;
    }

    /**
     * A parsed-mode message: the headers through the MIME parser, the rest laid
     * over them.
     *
     * @param array<string, string> $fields
     * @param array<string, array{filename: string, type: string, size: int, content: ?string, path: ?string}> $files
     * @param array<array-key, mixed> $charsets
     * @param array<string, mixed> $overrides
     */
    private static function parsed(array $fields, array $files, array $charsets, array $overrides): InboundMessage
    {
        $utf8 = static function (string $name) use ($fields, $charsets): ?string {
            if (!isset($fields[$name])) {
                return null;
            }
            $charset = \is_string($charsets[$name] ?? null) ? $charsets[$name] : 'utf-8';

            return MimeParser::toUtf8($fields[$name], $charset);
        };

        $headers = trim($fields['headers'] ?? '', "\r\n");
        $base = $headers !== ''
            ? InboundMessage::fromMime($headers . "\r\n\r\n", self::KEY)
            : new InboundMessage(receiver: self::KEY);

        $changes = ['raw' => null];

        $text = $utf8('text');
        if ($text !== null && $text !== '') {
            $changes['text'] = $text;
        }
        $html = $utf8('html');
        if ($html !== null && $html !== '') {
            $changes['html'] = $html;
        }

        $subject = $utf8('subject');
        if ($subject !== null && trim($subject) !== '') {
            $changes['subject'] = trim((string)preg_replace('/[\r\n\t]+/', ' ', $subject));
        }

        // The header block wins for addresses, because it is what the MIME
        // parser reads for every receiver; SendGrid's copies fill a gap.
        if ($base->from->isEmpty() && ($from = $utf8('from')) !== null) {
            $changes['from'] = Address::parse($from);
        }
        if ($base->to === [] && ($to = $utf8('to')) !== null) {
            $changes['to'] = Address::parseList($to);
        }
        if ($base->cc === [] && ($cc = $utf8('cc')) !== null) {
            $changes['cc'] = Address::parseList($cc);
        }

        $attachments = self::attachments($fields, $files);
        if ($attachments !== []) {
            $changes['attachments'] = $attachments;
        }

        return self::finish($base->with($changes), $overrides);
    }

    /**
     * SendGrid's envelope, verdicts and score laid over a message. Its SPF and
     * DKIM results win over whatever an `Authentication-Results` header in the
     * message said, and the rest of that header's verdicts (DMARC) are kept.
     *
     * @param array<string, mixed> $overrides
     */
    private static function finish(InboundMessage $message, array $overrides): InboundMessage
    {
        if (isset($overrides['auth'])) {
            $overrides['auth'] = $overrides['auth'] + $message->auth;
        }

        return $message->with($overrides);
    }

    /**
     * `attachment1` … `attachmentN`, in order, described by `attachment-info`.
     *
     * @param array<string, string> $fields
     * @param array<string, array{filename: string, type: string, size: int, content: ?string, path: ?string}> $files
     * @return list<InboundAttachment>
     */
    private static function attachments(array $fields, array $files): array
    {
        $info = self::json($fields['attachment-info'] ?? '');

        $cids = [];
        foreach (self::json($fields['content-ids'] ?? '') as $cid => $field) {
            if (\is_string($field) && \is_string($cid) && $cid !== '') {
                $cids[$field] = $cid;
            }
        }

        $names = [];
        foreach (array_keys($files) as $field) {
            if (preg_match('/^attachment(\d+)$/', $field, $m)) {
                $names[(int)$m[1]] = $field;
            }
        }
        ksort($names);

        $out = [];
        foreach ($names as $number => $field) {
            $file = $files[$field];
            $about = \is_array($info[$field] ?? null) ? $info[$field] : [];

            $type = trim((string)($about['type'] ?? ''));
            if ($type === '') {
                $type = trim($file['type']) !== '' ? trim(explode(';', $file['type'], 2)[0]) : 'application/octet-stream';
            }

            $cid = $about['content-id'] ?? $cids[$field] ?? null;
            $cid = \is_string($cid) ? trim($cid, " \t<>") : '';

            $name = \is_string($about['filename'] ?? null) && $about['filename'] !== ''
                ? $about['filename']
                : (\is_string($about['name'] ?? null) && $about['name'] !== '' ? $about['name'] : $file['filename']);

            $out[] = new InboundAttachment(
                filename: self::filename(MimeParser::decodeWords($name), $number),
                contentType: strtolower($type),
                size: $file['content'] !== null ? \strlen($file['content']) : $file['size'],
                contentId: $cid !== '' ? $cid : null,
                inline: $cid !== '',
                content: $file['content'],
                path: $file['content'] === null ? $file['path'] : null,
            );
        }

        return $out;
    }

    /** A bare, printable filename: no directories, no control characters. */
    private static function filename(string $name, int $number): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string)preg_replace('/[\x00-\x1F\x7F]+/', '', $name);
        $name = trim(str_replace([':', '*', '?', '"', '<', '>', '|'], '_', $name), " .\t");

        if ($name === '') {
            return 'attachment-' . $number . '.bin';
        }
        if (mb_strlen($name) > 180) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $stem = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 160);
            $name = $extension === '' ? $stem : $stem . '.' . mb_substr($extension, 0, 15);
        }

        return $name;
    }

    /** SendGrid's SPF field: the first word, lower-cased. */
    private static function spf(string $value): ?string
    {
        return preg_match('/^\s*([a-z]+)/i', $value, $m) ? strtolower($m[1]) : null;
    }

    /**
     * SendGrid's dkim field, `{@a.com : pass, @b.com : fail}` or `none`: pass
     * when any signature passed, otherwise the first result.
     */
    private static function dkim(string $value): ?string
    {
        if (preg_match_all('/:\s*([a-z]+)/i', $value, $m) && $m[1] !== []) {
            $results = array_map('strtolower', $m[1]);

            return \in_array('pass', $results, true) ? 'pass' : $results[0];
        }

        return preg_match('/^\s*([a-z]+)\s*$/i', $value, $m) ? strtolower($m[1]) : null;
    }

    /** @param array{content: ?string, path: ?string} $file */
    private static function bytes(array $file): ?string
    {
        if ($file['content'] !== null) {
            return $file['content'];
        }
        if ($file['path'] !== null && $file['path'] !== '' && is_file($file['path'])) {
            $bytes = @file_get_contents($file['path']);

            return $bytes === false ? null : $bytes;
        }

        return null;
    }

    /** @return array<array-key, mixed> */
    private static function json(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true, 16);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $config */
    private static function setting(array $config, string $key): string
    {
        $value = $config[$key] ?? '';

        return is_scalar($value) ? trim((string)$value) : '';
    }
}
