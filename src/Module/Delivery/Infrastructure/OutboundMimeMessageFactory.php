<?php

declare(strict_types=1);

namespace App\Module\Delivery\Infrastructure;

use App\Module\Delivery\Application\Contract\DeliveryTransportFailure;
use App\Module\Delivery\Application\Contract\OutboundMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class OutboundMimeMessageFactory
{
    public function __construct(private readonly string $canonicalUri = '')
    {
    }

    public function create(OutboundMessage $message): Email
    {
        $email = (new Email())
            ->from(new Address($message->senderAddress, $message->senderName))
            ->to($message->recipient)
            ->subject($message->subject)
            ->text($message->text)
            ->html($this->absoluteAssetUrls($message->html));
        if (null !== $message->replyTo) {
            $email->replyTo($message->replyTo);
        }
        $email->getHeaders()->addIdHeader('Message-ID', $message->messageId);
        if (null !== $message->unsubscribeUrl) {
            if (1 !== preg_match('#^https://[^\r\n<> ]+$#D', $message->unsubscribeUrl)) {
                throw new DeliveryTransportFailure('permanent', 'Unsubscribe URL is invalid.');
            }
            $email->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$message->unsubscribeUrl.'>');
            if ($message->oneClickUnsubscribe) {
                $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }
        } elseif ($message->oneClickUnsubscribe) {
            throw new DeliveryTransportFailure('permanent', 'One-click unsubscribe requires an HTTPS unsubscribe URL.');
        }

        return $email;
    }

    private function absoluteAssetUrls(string $html): string
    {
        // Match complete quoted attributes so text inside an alt/title is never rewritten.
        return preg_replace_callback('~<img\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*?>~i', function (array $tag): string {
            return preg_replace_callback('~(\s+)([a-zA-Z][\w:-]*)(\s*=\s*)(["\'])(.*?)\4~s', function (array $attribute): string {
                if ('src' !== strtolower($attribute[2]) || 1 !== preg_match('~^/template-assets/[a-f0-9]{48}\.(?:jpg|png|webp)$~D', $attribute[5])) {
                    return $attribute[0];
                }

                return $attribute[1].$attribute[2].$attribute[3].$attribute[4].$this->assetOrigin().$attribute[5].$attribute[4];
            }, $tag[0]) ?? throw new DeliveryTransportFailure('permanent', 'Template image attributes are invalid.');
        }, $html) ?? throw new DeliveryTransportFailure('permanent', 'Template image markup is invalid.');
    }

    private function assetOrigin(): string
    {
        $parts = parse_url($this->canonicalUri);
        if (false === filter_var($this->canonicalUri, FILTER_VALIDATE_URL) || false === $parts
            || 'https' !== ($parts['scheme'] ?? null) || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || !\in_array($parts['path'] ?? '', ['', '/'], true)
            || 1 === preg_match('/[\x00-\x20\x7f<>"\'\\\\]/', $this->canonicalUri)) {
            throw new DeliveryTransportFailure('permanent', 'Template images require a valid canonical HTTPS origin.', affectsCircuit: true);
        }

        return rtrim($this->canonicalUri, '/');
    }
}
