<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailSendgrid\Tests\Unit\Provider;

use Grav\Plugin\EmailSendgrid\Provider\Http;

/**
 * An {@see Http} that answers from a script and writes down what it was asked.
 *
 * The setup flow is three calls in a fixed order, and most of what can go wrong
 * with it is a call being made with the wrong body or in the wrong order rather
 * than a call failing. So this records every request as well as answering it,
 * and the tests assert on both halves.
 *
 * An answer is popped per call, so a test says what happens on the first call,
 * the second and the third by writing them down in that order. Running out of
 * answers is a failure with a message rather than a null, because "the code
 * made a fourth call nobody expected" is exactly the sort of thing worth
 * hearing about.
 */
final class FakeHttp implements Http
{
    /** @var list<array{method: string, url: string, body: array<array-key, mixed>|null, headers: array<string, string>}> */
    public array $calls = [];

    /** @param list<array{status: int, body: array<array-key, mixed>|null, error: string}> $answers */
    public function __construct(private array $answers = [])
    {
    }

    /** A client that answers success to everything the setup flow asks, in order. */
    public static function happy(string $id = 'wh_1', string $publicKey = 'MFkwEwYHKoZIzj0CAQ'): self
    {
        return new self([
            self::answer(200, ['webhooks' => []]),
            self::answer(201, ['id' => $id, 'url' => 'https://example.test/hook']),
            self::answer(200, ['id' => $id, 'public_key' => $publicKey]),
        ]);
    }

    /** @param array<array-key, mixed>|null $body */
    public static function answer(int $status, ?array $body = null, string $error = ''): array
    {
        return ['status' => $status, 'body' => $body, 'error' => $error];
    }

    public function json(string $method, string $url, ?array $body = null, array $headers = []): array
    {
        $this->calls[] = ['method' => strtoupper($method), 'url' => $url, 'body' => $body, 'headers' => $headers];

        if ($this->answers === []) {
            throw new \RuntimeException(sprintf(
                'The code made a call nobody scripted an answer for: %s %s',
                strtoupper($method),
                $url
            ));
        }

        return array_shift($this->answers);
    }

    /** @return array{method: string, url: string, body: array<array-key, mixed>|null, headers: array<string, string>} */
    public function call(int $index): array
    {
        return $this->calls[$index];
    }
}
