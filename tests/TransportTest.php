<?php

namespace Talario\Laravel\Tests;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\MailServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\TextPart;
use Talario\Laravel\TalarioServiceProvider;
use Talario\Laravel\TalarioTransport;
use Talario\Laravel\TalarioTransportException;

class TransportTest extends TestCase
{
    private function email(): Email
    {
        return (new Email)->from(new Address('shop@example.com', 'Example Shop'))
            ->to('customer@example.org')->subject('Bestellung – danke')->text('Vielen Dank!');
    }

    private function accepted(): array
    {
        return ['id' => '01TEST', 'message_id' => '<01test@example.com>', 'status' => 'accepted', 'mode' => 'live'];
    }

    private function http(): Factory
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['https://talario.eu/api/v1/messages' => $http->response($this->accepted(), 202)]);

        return $http;
    }

    public function test_maps_message_and_returns_server_message_id(): void
    {
        $http = $this->http();
        $email = $this->email()->html('<p>Danke!</p>')->cc('copy@example.org')
            ->bcc('secret@example.org')->replyTo(new Address('support@example.com', 'Support'))
            ->attach("\x00\xffPDF", 'rechnung.pdf', 'application/pdf');
        $email->getHeaders()->addTextHeader('X-Order-Id', '123');
        $email->getHeaders()->addIdHeader('In-Reply-To', 'previous@example.com');
        $email->getHeaders()->addIdHeader('Message-ID', 'local@example.com');
        $sent = (new TalarioTransport($http, 'test-key'))->send($email);

        self::assertSame('01test@example.com', $sent->getMessageId());
        self::assertSame('local@example.com', $email->getHeaders()->get('Message-ID')->getId());
        $http->assertSent(function (Request $request): bool {
            $data = $request->data();
            self::assertSame('POST', $request->method());
            self::assertTrue($request->hasHeader('Authorization', 'Bearer test-key'));
            self::assertSame('Example Shop', $data['from']['name']);
            self::assertSame('Bestellung – danke', $data['subject']);
            self::assertSame('Vielen Dank!', $data['text']);
            self::assertSame('<p>Danke!</p>', $data['html']);
            self::assertSame('copy@example.org', $data['cc'][0]['email']);
            self::assertSame('secret@example.org', $data['bcc'][0]['email']);
            self::assertSame('support@example.com', $data['reply_to'][0]['email']);
            self::assertSame('123', $data['headers']['X-Order-Id']);
            self::assertSame('<previous@example.com>', $data['headers']['In-Reply-To']);
            self::assertArrayNotHasKey('Message-ID', $data['headers']);
            self::assertSame("\x00\xffPDF", base64_decode($data['attachments'][0]['content']));

            return true;
        });
        $http->assertSentCount(1);
    }

    public function test_additional_envelope_recipients_are_hidden(): void
    {
        $http = $this->http();
        $envelope = new Envelope(new Address('shop@example.com'), [new Address('customer@example.org'), new Address('hidden@example.org')]);
        (new TalarioTransport($http, 'key'))->send($this->email(), $envelope);
        $http->assertSent(fn (Request $request) => $request['bcc'][0]['email'] === 'hidden@example.org');
    }

    public function test_excluded_bcc_is_not_reintroduced_by_mapping(): void
    {
        $http = $this->http();
        $envelope = new Envelope(new Address('shop@example.com'), [new Address('customer@example.org')]);
        (new TalarioTransport($http, 'key'))->send($this->email()->bcc('excluded@example.org'), $envelope);
        $http->assertSent(fn (Request $request) => !isset($request['bcc']));
    }

    public static function unsupportedMessages(): array
    {
        return [
            'sender' => ['sender'], 'return path' => ['return'],
            'multiple from' => ['from'], 'bcc only' => ['bcc'],
            'reserved header' => ['reserved'], 'unsupported header' => ['importance'],
            'duplicate header' => ['duplicate'], 'charset' => ['charset'], 'custom body' => ['custom'],
        ];
    }

    #[DataProvider('unsupportedMessages')]
    public function test_unsupported_features_fail_before_http(string $feature): void
    {
        $http = $this->http();
        $email = $this->email();
        match ($feature) {
            'sender' => $email->sender('other@example.com'),
            'return' => $email->returnPath('other@example.com'),
            'from' => $email->addFrom('other@example.com')->sender('shop@example.com'),
            'bcc' => $email->to()->bcc('hidden@example.org'),
            'reserved' => $email->getHeaders()->addTextHeader('X-Talario-Test', 'blocked'),
            'importance' => $email->getHeaders()->addTextHeader('Importance', 'high'),
            'duplicate' => $email->getHeaders()->addTextHeader('X-Test', 'one')->addTextHeader('X-Test', 'two'),
            'charset' => $email->text('text', 'iso-8859-1'),
            'custom' => $email->setBody(new TextPart('overrides the normal text body')),
        };
        try {
            (new TalarioTransport($http, 'key'))->send($email);
            self::fail('Unsupported feature was accepted.');
        } catch (TalarioTransportException $error) {
            self::assertStringContainsString('not supported', $error->getMessage());
        }
        $http->assertNothingSent();
    }

    public function test_explicit_envelope_cannot_add_visible_recipients(): void
    {
        $http = $this->http();
        $envelope = new Envelope(new Address('shop@example.com'), [new Address('other@example.org')]);
        $this->expectException(TalarioTransportException::class);
        try {
            (new TalarioTransport($http, 'key'))->send($this->email(), $envelope);
        } finally {
            $http->assertNothingSent();
        }
    }

    public static function statuses(): array
    {
        return array_map(fn ($status) => [$status], [301, 401, 403, 422, 429, 500, 503]);
    }

    #[DataProvider('statuses')]
    public function test_http_errors_are_sanitized_and_never_retried(int $status): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->response(['error' => ['code' => 'message_rejected', 'message' => 'secret-key private mail body']], $status, ['Retry-After' => '60'])]);
        try {
            (new TalarioTransport($http, 'secret-key'))->send($this->email());
            self::fail('HTTP error was accepted.');
        } catch (TalarioTransportException $error) {
            self::assertSame($status, $error->httpStatus);
            self::assertSame('message_rejected', $error->apiCode);
            self::assertSame(60, $error->retryAfter);
            self::assertStringNotContainsString('secret-key', $error->getMessage());
            self::assertStringNotContainsString('private mail', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        $http->assertSentCount(1);
    }

    public function test_connection_failures_do_not_expose_request_or_retry(): void
    {
        $calls = 0;
        $http = new Factory;
        $http->fake(function () use (&$calls) {
            ++$calls;
            throw new ConnectionException('Bearer secret-key: private message');
        });
        try {
            (new TalarioTransport($http, 'secret-key'))->send($this->email());
            self::fail('Connection failure was accepted.');
        } catch (TalarioTransportException $error) {
            self::assertStringContainsString('acceptance is unknown', $error->getMessage());
            self::assertStringNotContainsString('secret-key', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        self::assertSame(1, $calls);
    }

    public function test_invalid_success_response_is_not_silently_accepted(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->response(['status' => 'accepted'], 202)]);
        $this->expectException(TalarioTransportException::class);
        (new TalarioTransport($http, 'key'))->send($this->email());
    }

    public function test_sandbox_response_is_supported_without_client_side_mode_switch(): void
    {
        $http = new Factory;
        $http->fake(['*' => $http->response(array_replace($this->accepted(), ['mode' => 'sandbox']), 202)]);
        self::assertSame('01test@example.com', (new TalarioTransport($http, 'key'))->send($this->email())->getMessageId());
        $http->assertSent(fn (Request $request) => !isset($request['mode']));
    }

    public function test_http_options_disable_redirects_and_bound_timeouts(): void
    {
        $http = new Factory;
        $http->fake(function (Request $request, array $options) use ($http) {
            self::assertFalse($options['allow_redirects']);
            self::assertSame(45.0, $options['timeout']);
            self::assertSame(5.0, $options['connect_timeout']);

            return $http->response($this->accepted(), 202);
        });
        (new TalarioTransport($http, 'key', 'https://talario.eu/api/v1/', 45, 5))->send($this->email());
    }

    public function test_binary_file_and_stream_attachments(): void
    {
        $http = $this->http();
        $file = tempnam(sys_get_temp_dir(), 'talario');
        file_put_contents($file, "\x00\xfeFile");
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\x00\xfdStream");
        rewind($stream);
        try {
            (new TalarioTransport($http, 'key'))->send($this->email()->attachFromPath($file, 'file.bin', 'application/octet-stream')->attach($stream, 'stream.bin', 'application/octet-stream'));
            $http->assertSent(function (Request $request): bool {
                self::assertSame("\x00\xfeFile", base64_decode($request['attachments'][0]['content']));
                self::assertSame("\x00\xfdStream", base64_decode($request['attachments'][1]['content']));

                return true;
            });
        } finally {
            fclose($stream);
            unlink($file);
        }
    }

    public static function endpoints(): array
    {
        return array_map(fn ($url) => [$url], ['http://talario.eu/api/v1', 'https://key@talario.eu/api/v1', 'https://talario.eu/api/v1?key=secret', 'https://talario.eu/api/v1#part']);
    }

    #[DataProvider('endpoints')]
    public function test_unsafe_endpoints_are_rejected(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TalarioTransport(new Factory, 'key', $url);
    }

    public function test_provider_registers_mailer_and_respects_application_overrides(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->instance('config', new Repository(['talario' => ['api_key' => 'key']]));
        $http = $this->http();
        $app->instance(Factory::class, $http);
        $app->register(MailServiceProvider::class);
        $app->register(TalarioServiceProvider::class);
        $manager = $app->make('mail.manager');
        $config = $app['config']->get('mail.mailers.talario');
        self::assertSame(['transport' => 'talario'], $config);
        $transport = $manager->createSymfonyTransport($config);
        self::assertInstanceOf(TalarioTransport::class, $transport);
        $transport->send($this->email());
        $http->assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer key'));

        $custom = new Application(sys_get_temp_dir());
        $custom->instance('config', new Repository(['mail' => ['mailers' => ['talario' => ['transport' => 'talario', 'timeout' => 45]]]]));
        $custom->register(TalarioServiceProvider::class);
        self::assertSame(45, $custom['config']->get('mail.mailers.talario.timeout'));
        $app->flush();
        $custom->flush();
    }
}
