<?php

namespace Talario\Laravel;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;

/** Maps only features the current structured sending API can preserve. */
class MessagePayload
{
    public function build(SentMessage $sent): array
    {
        $email = $sent->getOriginalMessage();
        if (!$email instanceof Email) {
            $this->unsupported('Raw MIME messages');
        }
        // Read the public base implementation, bypassing Email's generated-body accessor.
        // A body installed with setBody() may override otherwise populated text/html fields.
        if ((new \ReflectionMethod(Message::class, 'getBody'))->invoke($email) !== null) {
            $this->unsupported('Custom MIME bodies');
        }
        $from = $email->getFrom();
        if (count($from) !== 1) {
            $this->unsupported('Multiple From addresses');
        }
        if ($email->getSender() !== null || $email->getReturnPath() !== null
            || $sent->getEnvelope()->getSender()->getAddress() !== $from[0]->getAddress()) {
            $this->unsupported('Custom Sender, Return-Path or envelope sender');
        }

        $payload = ['from' => $this->address($from[0]), 'subject' => $email->getSubject() ?? ''];
        $envelope = [];
        foreach ($sent->getEnvelope()->getRecipients() as $address) {
            $envelope[strtolower($address->getAddress())] = $address;
        }
        $visible = [];
        foreach (['to' => $email->getTo(), 'cc' => $email->getCc()] as $field => $addresses) {
            foreach ($addresses as $address) {
                $key = strtolower($address->getAddress());
                // Never add a recipient excluded by an explicit envelope.
                if (!isset($envelope[$key])) {
                    $this->unsupported('Visible recipients outside the SMTP envelope');
                }
                $payload[$field][] = $this->address($address);
                $visible[$key] = true;
            }
        }
        if (empty($payload['to'])) {
            $this->unsupported('Messages without a To recipient (including Bcc-only messages)');
        }
        foreach ($envelope as $key => $address) {
            if (!isset($visible[$key])) {
                $payload['bcc'][] = $this->address($address);
            }
        }
        if ($email->getReplyTo()) {
            $payload['reply_to'] = array_map($this->address(...), $email->getReplyTo());
        }
        foreach (['text' => $email->getTextBody(), 'html' => $email->getHtmlBody()] as $field => $body) {
            if ($body !== null) {
                $charset = $field === 'text' ? $email->getTextCharset() : $email->getHtmlCharset();
                if ($charset !== null && strcasecmp($charset, 'utf-8') !== 0) {
                    $this->unsupported('Non-UTF-8 bodies');
                }
                if (is_resource($body)) {
                    if (stream_get_meta_data($body)['seekable']) {
                        rewind($body);
                    }
                    $body = stream_get_contents($body);
                    if ($body === false) {
                        $this->unsupported('Unreadable body streams');
                    }
                }
                $payload[$field] = $body;
            }
        }
        if (!isset($payload['text']) && !isset($payload['html'])) {
            $this->unsupported('Custom MIME bodies');
        }

        $managed = ['from', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'date', 'message-id', 'mime-version'];
        $allowed = ['list-unsubscribe', 'list-unsubscribe-post', 'list-id', 'in-reply-to', 'references'];
        $seen = [];
        foreach ($email->getHeaders()->all() as $header) {
            $name = $header->getName();
            $lower = strtolower($name);
            if (in_array($lower, $managed, true)) {
                continue;
            }
            if ((!preg_match('/^x-[a-z0-9-]+$/D', $lower) || str_starts_with($lower, 'x-talario-'))
                && !in_array($lower, $allowed, true)) {
                $this->unsupported('This message contains a header the API cannot preserve');
            }
            if (isset($seen[$lower])) {
                $this->unsupported('Repeated custom headers');
            }
            $seen[$lower] = true;
            $payload['headers'][$name] = preg_replace('/\r\n[ \t]+/', ' ', $header->getBodyAsString());
        }
        foreach ($email->getAttachments() as $attachment) {
            if ($attachment->getDisposition() === 'inline' || $attachment->hasContentId()) {
                $this->unsupported('Inline attachments and embedded images');
            }
            $payload['attachments'][] = [
                'filename' => $attachment->getFilename() ?? 'attachment',
                'content' => base64_encode($attachment->getBody()),
                'content_type' => $attachment->getContentType(),
            ];
        }

        return $payload;
    }

    private function address(Address $address): array
    {
        return ['email' => $address->getAddress(), 'name' => $address->getName()];
    }

    private function unsupported(string $feature): never
    {
        throw new TalarioTransportException($feature.' are not supported by the Talario API v1 transport. Use SMTP or adapt the message.');
    }
}
