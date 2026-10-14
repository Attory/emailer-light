<?php

declare(strict_types=1);

namespace App\Tests\Module\Delivery;

use App\Module\Delivery\Application\Contract\DeliveryTransportFailure;
use App\Module\Delivery\Application\Contract\OutboundMessage;
use App\Module\Delivery\Infrastructure\OutboundMimeMessageFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OutboundMimeMessageFactoryTest extends TestCase
{
    public function testOnlyUploadedImageSourcesBecomeAbsoluteWithoutChangingSnapshotOrOtherContent(): void
    {
        $asset = '/template-assets/'.str_repeat('a', 48);
        $html = '<p>こんにちは</p><a href="https://example.test/event">Event</a>'
            .'<img alt="src=\''.$asset.'.png\'" src="'.$asset.'.png" width="64">'
            .'<IMG SRC=\''.$asset.'.jpg\'><img src="'.$asset.'.webp">'
            .'<img src="https://example.test/r/opaque/open.gif">'
            .'<a href="'.$asset.'.png">Asset link</a><p>'.$asset.'.png</p>';
        $message = $this->message($html);
        $mime = (new OutboundMimeMessageFactory('https://emailer.example.test:8443/'))->create($message);

        self::assertSame($html, $message->html);
        self::assertSame(str_replace(
            ['src="'.$asset, 'SRC=\''.$asset],
            ['src="https://emailer.example.test:8443'.$asset, 'SRC=\'https://emailer.example.test:8443'.$asset],
            $html,
        ), $mime->getHtmlBody());
        self::assertStringContainsString('List-Unsubscribe-Post: List-Unsubscribe=One-Click', $mime->getHeaders()->toString());
    }

    #[DataProvider('invalidOrigins')]
    public function testUploadedImagesFailClosedWithAnInvalidOrigin(string $origin): void
    {
        try {
            (new OutboundMimeMessageFactory($origin))->create($this->message('<img src="/template-assets/'.str_repeat('b', 48).'.png">'));
            self::fail('Invalid image origin was accepted.');
        } catch (DeliveryTransportFailure $failure) {
            self::assertSame('permanent', $failure->classification);
            self::assertTrue($failure->affectsCircuit);
            self::assertStringContainsString('canonical HTTPS origin', $failure->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOrigins(): iterable
    {
        yield 'missing' => [''];
        yield 'insecure' => ['http://emailer.example.test'];
        yield 'credentials' => ['https://user@example.test'];
        yield 'path' => ['https://emailer.example.test/other'];
        yield 'query' => ['https://emailer.example.test/?other=1'];
        yield 'fragment' => ['https://emailer.example.test/#other'];
        yield 'control character' => ["https://emailer.example.test\n"];
        yield 'backslash' => ['https://emailer.example.test\\other'];
    }

    public function testMessagesWithoutUploadedImagesDoNotRequireAnAssetOrigin(): void
    {
        $html = '<p>No images</p><img src="https://example.test/r/opaque/open.gif">';
        self::assertSame($html, (new OutboundMimeMessageFactory())->create($this->message($html))->getHtmlBody());
    }

    private function message(string $html): OutboundMessage
    {
        return new OutboundMessage('recipient@example.test', 'sender@example.test', 'Synthetic sender', null, '[TEST] Asset URLs', $html, 'Synthetic body', 'asset-test@example.test', 'https://emailer.example.test/r/opaque', true);
    }
}
