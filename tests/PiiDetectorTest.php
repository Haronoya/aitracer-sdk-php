<?php

declare(strict_types=1);

namespace AITracer\Tests;

use AITracer\PiiDetector;
use PHPUnit\Framework\TestCase;

class PiiDetectorTest extends TestCase
{
    public function testEmailMasking(): void
    {
        $detector = new PiiDetector(['email'], 'mask');

        $result = $detector->process('Contact us at test@example.com');

        $this->assertEquals('Contact us at [email]', $result);
    }

    public function testPhoneMasking(): void
    {
        $detector = new PiiDetector(['phone'], 'mask');

        $result = $detector->process('Call us at 123-456-7890');

        $this->assertEquals('Call us at [phone]', $result);
    }

    public function testCreditCardMasking(): void
    {
        $detector = new PiiDetector(['credit_card'], 'mask');

        $result = $detector->process('Card: 4111111111111111');

        $this->assertEquals('Card: [credit_card]', $result);
    }

    public function testRedactAction(): void
    {
        $detector = new PiiDetector(['email'], 'redact');

        $result = $detector->process('Email: test@example.com');

        $this->assertStringContainsString('***', $result);
        $this->assertStringNotContainsString('test@example.com', $result);
    }

    public function testHashAction(): void
    {
        $detector = new PiiDetector(['email'], 'hash');

        $result = $detector->process('Email: test@example.com');

        $this->assertStringNotContainsString('test@example.com', $result);
        $this->assertEquals(16, strlen(str_replace('Email: ', '', $result)));
    }

    public function testNoneAction(): void
    {
        $detector = new PiiDetector(['email'], 'none');

        $result = $detector->process('Email: test@example.com');

        $this->assertEquals('Email: test@example.com', $result);
    }

    public function testNestedArrayProcessing(): void
    {
        $detector = new PiiDetector(['email'], 'mask');

        $data = [
            'user' => [
                'name' => 'John',
                'email' => 'john@example.com',
                'contacts' => [
                    ['email' => 'contact1@example.com'],
                    ['email' => 'contact2@example.com'],
                ],
            ],
        ];

        $result = $detector->process($data);

        $this->assertEquals('[email]', $result['user']['email']);
        $this->assertEquals('[email]', $result['user']['contacts'][0]['email']);
        $this->assertEquals('[email]', $result['user']['contacts'][1]['email']);
        $this->assertEquals('John', $result['user']['name']);
    }

    public function testDetectWithoutMasking(): void
    {
        $detector = new PiiDetector(['email', 'phone']);

        $data = [
            'message' => 'Contact test@example.com or call 123-456-7890',
        ];

        $detections = $detector->detect($data);

        $this->assertCount(2, $detections);
        $this->assertEquals('email', $detections[0]['type']);
        $this->assertEquals('test@example.com', $detections[0]['value']);
        $this->assertEquals('phone', $detections[1]['type']);
    }

    public function testMultiplePiiInSameString(): void
    {
        $detector = new PiiDetector(['email', 'phone'], 'mask');

        $result = $detector->process('Email: test@example.com, Phone: 123-456-7890');

        $this->assertEquals('Email: [email], Phone: [phone]', $result);
    }

    public function testCustomPattern(): void
    {
        $detector = new PiiDetector([], 'mask');
        $detector->addPattern('order_id', '/ORD-\d{6}/');

        $result = $detector->process('Your order ORD-123456 is confirmed');

        $this->assertEquals('Your order [order_id] is confirmed', $result);
    }

    public function testJapanesePhoneNumber(): void
    {
        $detector = new PiiDetector(['japanese_phone'], 'mask');

        $result = $detector->process('電話: 03-1234-5678');

        $this->assertEquals('電話: [japanese_phone]', $result);
    }

    /**
     * Same cases as the Python SDK (sdk/tests/test_pii.py).
     *
     * @return array<string, array{string, string, string}>
     */
    public static function adjacentToJapaneseProvider(): array
    {
        return [
            'email' => ['email', '連絡先はtaro@example.comです', '連絡先は[email]です'],
            'phone' => ['phone', '電話090-1234-5678まで', '電話[phone]まで'],
            'credit_card' => ['credit_card', 'カード4111-1111-1111-1111で決済', 'カード[credit_card]で決済'],
            'ssn' => ['ssn', '番号123-45-6789です', '番号[ssn]です'],
            'ip_address' => ['ip_address', '接続元192.168.0.1から', '接続元[ip_address]から'],
        ];
    }

    /**
     * @dataProvider adjacentToJapaneseProvider
     */
    public function testMasksValueAdjacentToJapanese(string $type, string $text, string $expected): void
    {
        $detector = new PiiDetector([$type], 'mask');

        $this->assertEquals($expected, $detector->process($text));
    }

    public function testJapaneseMobileNumberIsMaskedWhole(): void
    {
        // The US-style alternative used to match "090-1234" first and leave "-5678"
        $detector = new PiiDetector(['phone'], 'mask');

        $this->assertEquals('電話は [phone] まで', $detector->process('電話は 090-1234-5678 まで'));
    }

    public function testCardNumberIsNotPartiallyMaskedAsPhone(): void
    {
        // Phone used to be applied first and leave the last 6 digits of the card
        $detector = new PiiDetector(['phone', 'credit_card'], 'mask');

        $this->assertEquals('カード[credit_card]で決済', $detector->process('カード4111111111111111で決済'));
    }

    public function testDigitsInsideAsciiTokenAreNotPhone(): void
    {
        $detector = new PiiDetector(['phone'], 'mask');
        $text = 'order ABC09012345678XYZ shipped';

        $this->assertEquals($text, $detector->process($text));
    }

    public function testEmailTldDoesNotAcceptPipe(): void
    {
        $detector = new PiiDetector(['email'], 'mask');
        $text = 'invalid a@example.|o address';

        $this->assertEquals($text, $detector->process($text));
    }
}
