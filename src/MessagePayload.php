<?php

namespace Talario\Laravel;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\Part\AbstractMultipartPart;
use Symfony\Component\Mime\Part\AbstractPart;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\TextPart;

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
        // Symfony resolves cid:filename aliases and assigns Content-IDs during body generation.
        // Read these prepared body parts rather than the unprocessed getHtmlBody() value.
        $bodies = $this->bodies($email->getBody());
        foreach ($bodies as $field => $body) {
            if ($body !== null) {
                $charset = $field === 'text' ? $email->getTextCharset() : $email->getHtmlCharset();
                if ($charset !== null && strcasecmp($charset, 'utf-8') !== 0) {
                    $this->unsupported('Non-UTF-8 bodies');
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
        $contentIds = [];
        foreach ($email->getAttachments() as $attachment) {
            $part = [
                'filename' => $attachment->getFilename() ?? 'attachment',
                'content' => base64_encode($attachment->getBody()),
                'content_type' => $attachment->getContentType(),
            ];
            if ($attachment->getDisposition() === 'inline' || $attachment->hasContentId()) {
                $contentId = $attachment->getContentId();
                if (strlen($contentId) > 255 || !preg_match('/\A[A-Za-z0-9._+-]+@[A-Za-z0-9.-]+\z/', $contentId)) {
                    $this->unsupported('Content-IDs outside the API format');
                }
                if (isset($contentIds[$contentId])) {
                    $this->unsupported('Duplicate Content-IDs');
                }
                $contentIds[$contentId] = true;
                $part['disposition'] = $attachment->getDisposition();
                $part['content_id'] = $contentId;
            }
            $payload['attachments'][] = $part;
        }

        return $payload;
    }

    /** @return array<string, string> */
    private function bodies(AbstractPart $part): array
    {
        if ($part instanceof DataPart) {
            return [];
        }
        if ($part instanceof TextPart) {
            return match ($part->getMediaSubtype()) {
                'plain' => ['text' => $part->getBody()],
                'html' => ['html' => $part->getBody()],
                default => [],
            };
        }
        $bodies = [];
        if ($part instanceof AbstractMultipartPart) {
            foreach ($part->getParts() as $child) {
                $bodies = array_replace($bodies, $this->bodies($child));
            }
        }

        return $bodies;
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
