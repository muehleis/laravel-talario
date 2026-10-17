<?php

namespace Talario\Laravel;

use Illuminate\Http\Client\Factory;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

class TalarioTransport extends AbstractTransport
{
    public function __construct(
        private Factory $http,
        #[\SensitiveParameter] private string $apiKey,
        private string $endpoint = 'https://talario.eu/api/v1',
        private float $timeout = 60,
        private float $connectTimeout = 10,
    ) {
        parent::__construct();

        $url = parse_url($endpoint);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            throw new \InvalidArgumentException('Talario requires an HTTPS API endpoint without credentials, query or fragment.');
        }
        if (trim($apiKey) === '' || preg_match('/[\r\n]/', $apiKey)) {
            throw new \InvalidArgumentException('Configure a valid TALARIO_API_KEY.');
        }
        if (!is_finite($timeout) || !is_finite($connectTimeout) || $timeout <= 0 || $connectTimeout <= 0) {
            throw new \InvalidArgumentException('Talario timeouts must be positive, finite numbers.');
        }
    }

    public function __toString(): string
    {
        return 'talario';
    }

    protected function doSend(SentMessage $message): void
    {
        $payload = (new MessagePayload)->build($message);

        try {
            // No retries: API v1 does not yet provide an idempotency key.
            $response = $this->http->withToken($this->apiKey)
                ->acceptJson()->asJson()->withoutRedirecting()
                ->timeout($this->timeout)->connectTimeout($this->connectTimeout)
                ->post(rtrim($this->endpoint, '/').'/messages', $payload);
        } catch (\Exception) {
            // Do not attach the HTTP exception: it can contain credentials or mail content.
            throw new TalarioTransportException('Talario request failed; acceptance is unknown. Check Talario before retrying.');
        }

        if ($response->status() !== 202) {
            $code = $response->json('error.code');
            $code = is_string($code) && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code) ? $code : null;
            $retry = $response->header('Retry-After');
            $retry = ctype_digit($retry) ? min((int) $retry, 86400) : null;

            throw new TalarioTransportException(
                'Talario API returned HTTP '.$response->status().'.',
                $response->status(), $code, $retry,
            );
        }

        $data = $response->json();
        if (!is_array($data) || !is_string($data['id'] ?? null) || $data['id'] === ''
            || !is_string($data['message_id'] ?? null)
            || !preg_match('/^<[^<>\s]+@[^<>\s]+>$/D', $data['message_id'])
            || ($data['status'] ?? null) !== 'accepted'
            || !in_array($data['mode'] ?? null, ['live', 'sandbox'], true)) {
            throw new TalarioTransportException('Talario returned an invalid acceptance response; check Talario before retrying.', 202);
        }

        $message->setMessageId(trim($data['message_id'], '<>'));
    }
}
