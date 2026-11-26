<?php

namespace Tests\Evaluations\ConversationAgent;

use Closure;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/** Shared process-local paid-request safeguards for the Developer evaluation suites. */
final class EvaluationProviderGuard
{
    private array $fingerprints = [];

    private int $requests = 0;

    private int $bytes = 0;

    private int $tokens = 0;

    /** @param array<string, int> $limits */
    public function __construct(private array $limits) {}

    public function beginAttempt(): void
    {
        $this->fingerprints = [];
    }

    public function recordTokens(int $tokens): void
    {
        $this->tokens += $tokens;
    }

    /** @return array{http_requests: int, request_bytes: int, reported_tokens: int} */
    public function usage(): array
    {
        return ['http_requests' => $this->requests, 'request_bytes' => $this->bytes, 'reported_tokens' => $this->tokens];
    }

    public function install(?Closure $beforeRequest = null): void
    {
        Http::globalMiddleware(function (callable $handler) use ($beforeRequest): callable {
            return function (RequestInterface $request, array $options) use ($handler, $beforeRequest) {
                if ((string) $request->getUri() !== 'https://api.openai.com/v1/responses' || $request->getMethod() !== 'POST') {
                    throw new RuntimeException('smoke_endpoint_guard');
                }
                // Preserve empty JSON objects, including parameterless tools' schema property maps.
                $body = json_decode((string) $request->getBody(), flags: JSON_THROW_ON_ERROR);
                $body->max_output_tokens = $body->model === 'gpt-6-luna'
                    ? $this->limits['luna_output_tokens_per_request']
                    : $this->limits['output_tokens_per_request'];
                $body->store = false;
                $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $fingerprint = hash('sha256', $json);
                if (isset($this->fingerprints[$fingerprint])) {
                    throw new RuntimeException('smoke_retry_blocked');
                }
                if ($this->requests >= $this->limits['http_requests']
                    || count($this->fingerprints) >= $this->limits['requests_per_turn']
                    || strlen($json) > $this->limits['request_bytes']
                    || $this->bytes + strlen($json) > $this->limits['total_request_bytes']
                    || $this->tokens >= $this->limits['reported_tokens']) {
                    throw new RuntimeException('smoke_budget_exceeded');
                }
                $this->fingerprints[$fingerprint] = true;
                $this->requests++;
                $this->bytes += strlen($json);
                if ($beforeRequest !== null) {
                    $beforeRequest();
                }
                $request = $request->withBody(Utils::streamFor($json))->withoutHeader('Content-Length');
                $options['timeout'] = $this->limits['timeout_seconds'];
                $options['connect_timeout'] = 10;
                $options['allow_redirects'] = false;

                // SDK 1.4 retries short Retry-After responses; also fence identical retransmissions.
                return $handler($request, $options)->then(fn (ResponseInterface $response): ResponseInterface => $response->withoutHeader('Retry-After'));
            };
        });
    }
}
