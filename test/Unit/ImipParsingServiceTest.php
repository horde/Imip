<?php

declare(strict_types=1);

namespace Horde\Imip\Test\Unit;

use Horde\Imip\Exception\ImipException;
use Horde\Imip\ImipParsingService;
use Horde\Mime\MimeParser;
use Horde\Mime\PartBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImipParsingService::class)]
final class ImipParsingServiceTest extends TestCase
{
    private ImipParsingService $service;

    protected function setUp(): void
    {
        $this->service = new ImipParsingService();
    }

    private function sampleIcs(string $method = 'REQUEST'): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'METHOD:' . $method,
            'BEGIN:VEVENT',
            'UID:test-uid@example.com',
            'SUMMARY:Team Meeting',
            'DTSTART:20260601T100000Z',
            'DTEND:20260601T110000Z',
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);
    }

    #[Test]
    public function parseExtractsItipMessageFromCalendarPart(): void
    {
        $part = (new PartBuilder())
            ->setContentType('text/calendar', ['charset' => 'UTF-8', 'method' => 'REQUEST'])
            ->setBody($this->sampleIcs('REQUEST'))
            ->build();

        $msg = $this->service->parse($part, 'sender@example.com');

        $this->assertNotNull($msg);
        $this->assertSame('REQUEST', $msg->method->value);
        $this->assertSame('sender@example.com', $msg->actorEmail);
        $this->assertNotNull($msg->getFirstEvent());
        $this->assertSame('Team Meeting', $msg->getFirstEvent()->getSummary());
    }

    #[Test]
    public function parseFindsCalendarPartInMultipart(): void
    {
        $textPart = PartBuilder::text('You have been invited.', 'plain', 'UTF-8');
        $calPart = (new PartBuilder())
            ->setContentType('text/calendar', ['charset' => 'UTF-8', 'method' => 'REQUEST'])
            ->setBody($this->sampleIcs('REQUEST'));

        $multipart = PartBuilder::multipart('alternative', $textPart, $calPart)->build();

        $msg = $this->service->parse($multipart, 'organizer@example.com');

        $this->assertNotNull($msg);
        $this->assertSame('REQUEST', $msg->method->value);
    }

    #[Test]
    public function parseReturnsNullWhenNoCalendarPart(): void
    {
        $part = PartBuilder::text('Hello world', 'plain', 'UTF-8')->build();

        $msg = $this->service->parse($part, 'sender@example.com');

        $this->assertNull($msg);
    }

    #[Test]
    public function parseReturnsNullForEmptyCalendarBody(): void
    {
        $part = (new PartBuilder())
            ->setContentType('text/calendar', ['charset' => 'UTF-8'])
            ->setBody('')
            ->build();

        $msg = $this->service->parse($part, 'sender@example.com');

        $this->assertNull($msg);
    }

    #[Test]
    public function parseThrowsOnInvalidIcalData(): void
    {
        $part = (new PartBuilder())
            ->setContentType('text/calendar', ['charset' => 'UTF-8'])
            ->setBody('NOT VALID ICALENDAR DATA')
            ->build();

        $this->expectException(ImipException::class);
        $this->service->parse($part, 'sender@example.com');
    }

    #[Test]
    public function parseThrowsWhenMethodMissing(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VEVENT',
            'UID:no-method@example.com',
            'SUMMARY:No Method',
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);

        $part = (new PartBuilder())
            ->setContentType('text/calendar', ['charset' => 'UTF-8'])
            ->setBody($ics)
            ->build();

        $this->expectException(ImipException::class);
        $this->service->parse($part, 'sender@example.com');
    }

    #[Test]
    public function parseHandlesReplyMethod(): void
    {
        $part = (new PartBuilder())
            ->setContentType('text/calendar', ['charset' => 'UTF-8', 'method' => 'REPLY'])
            ->setBody($this->sampleIcs('REPLY'))
            ->build();

        $msg = $this->service->parse($part, 'attendee@example.com');

        $this->assertNotNull($msg);
        $this->assertSame('REPLY', $msg->method->value);
        $this->assertSame('attendee@example.com', $msg->actorEmail);
    }
}
