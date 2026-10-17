<?php

namespace Talario\Laravel\Tests;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Talario\Laravel\TalarioTransport;
use Talario\Laravel\TalarioTransportException;

class InlineImagesTest extends TestCase
{
    private function email(): Email
    {
        return (new Email)->from('shop@example.com')->to('customer@example.org')->subject('Inline images');
    }

    private function http(): Factory
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => $http->response([
            'id' => '01TEST', 'message_id' => '<01test@example.com>', 'status' => 'accepted', 'mode' => 'live',
        ], 202)]);

        return $http;
    }

    public function test_laravel_embed_data_keeps_each_cid_and_binary_content(): void
    {
        $email = $this->email()->text('Text alternative')->attach('PDF', 'invoice.pdf', 'application/pdf');
        $message = new Message($email);
        $first = $message->embedData("\x00\xffPNG", 'logo.png', 'image/png');
        $second = $message->embedData("\x00\xfePNG", 'logo.png', 'image/png');
        $email->html('<img src="'.$first.'"><img src="'.$second.'">');
        $http = $this->http();

        (new TalarioTransport($http, 'key'))->send($email);

        $http->assertSent(function (Request $request) use ($first, $second): bool {
            self::assertSame('Text alternative', $request['text']);
            self::assertStringContainsString($first, $request['html']);
            self::assertStringContainsString($second, $request['html']);
            $parts = $request['attachments'];
            self::assertArrayNotHasKey('disposition', $parts[0]);
            self::assertSame('invoice.pdf', $parts[0]['filename']);
            self::assertSame('inline', $parts[1]['disposition']);
            self::assertSame('inline', $parts[2]['disposition']);
            self::assertSame(substr($first, 4), $parts[1]['content_id']);
            self::assertSame(substr($second, 4), $parts[2]['content_id']);
            self::assertNotSame($parts[1]['content_id'], $parts[2]['content_id']);
            self::assertSame("\x00\xffPNG", base64_decode($parts[1]['content']));
            self::assertSame("\x00\xfePNG", base64_decode($parts[2]['content']));

            return true;
        });
    }

    public function test_laravel_embed_loads_image_from_disk(): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'talario-image');
        $path = $temporary.'.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j9WQAAAAASUVORK5CYII=');
        file_put_contents($path, $png);
        try {
            $email = $this->email();
            $cid = (new Message($email))->embed($path);
            $email->html('<img src="'.$cid.'">');
            $http = $this->http();
            (new TalarioTransport($http, 'key'))->send($email);
            $http->assertSent(function (Request $request) use ($cid, $png): bool {
                self::assertSame('image/png', $request['attachments'][0]['content_type']);
                self::assertSame($png, base64_decode($request['attachments'][0]['content']));
                self::assertStringContainsString('cid:'.$request['attachments'][0]['content_id'], $request['html']);
                self::assertSame(substr($cid, 4), $request['attachments'][0]['content_id']);

                return true;
            });
        } finally {
            unlink($path);
            unlink($temporary);
        }
    }

    public function test_symfony_filename_aliases_use_prepared_html_with_matching_ids(): void
    {
        $email = $this->email()->html('<img src="cid:logo.png"><img src="cid:logo.png.large">');
        $first = (new DataPart('first', 'logo.png', 'image/png'))->asInline();
        $second = (new DataPart('second', 'logo.png.large', 'image/png'))->asInline();
        $email->addPart($first)->addPart($second);
        $http = $this->http();
        (new TalarioTransport($http, 'key'))->send($email);

        $http->assertSent(function (Request $request): bool {
            self::assertSame('<img src="cid:'.$request['attachments'][0]['content_id'].'"><img src="cid:'.$request['attachments'][1]['content_id'].'">', $request['html']);
            self::assertSame('inline', $request['attachments'][0]['disposition']);
            self::assertSame('inline', $request['attachments'][1]['disposition']);

            return true;
        });
    }

    public function test_duplicate_ids_fail_before_http(): void
    {
        $email = $this->email()->html('<img src="cid:logo@example.com">');
        $email->addPart((new DataPart('one', 'one.png', 'image/png'))->asInline()->setContentId('logo@example.com'));
        $email->addPart((new DataPart('two', 'two.png', 'image/png'))->asInline()->setContentId('logo@example.com'));
        $http = $this->http();
        $this->expectException(TalarioTransportException::class);
        try {
            (new TalarioTransport($http, 'key'))->send($email);
        } finally {
            $http->assertNothingSent();
        }
    }

    public function test_invalid_ids_fail_before_http(): void
    {
        $email = $this->email()->html('test')->addPart((new DataPart('one', 'one.png', 'image/png'))->asInline()->setContentId('bad/id@example.com'));
        $http = $this->http();
        $this->expectException(TalarioTransportException::class);
        try {
            (new TalarioTransport($http, 'key'))->send($email);
        } finally {
            $http->assertNothingSent();
        }
    }
}
