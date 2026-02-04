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
}
