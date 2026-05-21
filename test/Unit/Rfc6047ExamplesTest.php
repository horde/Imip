<?php

declare(strict_types=1);

namespace Horde\Imip\Test\Unit;

use Horde\Imip\ImipParsingService;
use Horde\Mime\PartBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests iMIP parsing using scenarios derived from RFC 6047 §4 canonical examples.
 */
#[CoversClass(ImipParsingService::class)]
final class Rfc6047ExamplesTest extends TestCase
{
    private ImipParsingService $service;

    protected function setUp(): void
    {
        $this->service = new ImipParsingService();
    }

    /**
     * RFC 6047 §4.1: Single text/calendar part with ATTACH property.
     */
    #[Test]
    public function parseSingleCalendarPart(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'PRODID:-//Example/ExampleCalendarClient//EN',
            'METHOD:REQUEST',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'ORGANIZER:mailto:man@netscape.example.com',
            'ATTENDEE;ROLE=CHAIR;PARTSTAT=ACCEPTED:mailto:man@netscape.example.com',
            'ATTENDEE;RSVP=YES:mailto:stevesil@microsoft.example.com',
            'DTSTAMP:19970611T190000Z',
            'DTSTART:19970701T210000Z',
            'DTEND:19970701T230000Z',
            'SUMMARY:Phone Conference',
            'DESCRIPTION:Please review the attached document.',
            'UID:calsvr.example.com-873970198738777',
            'ATTACH:ftp://ftp.bar.example.com/pub/docs/foo.doc',
            'STATUS:CONFIRMED',
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);

        $part = (new PartBuilder())
            ->setContentType('text/calendar', ['charset' => 'US-ASCII', 'method' => 'REQUEST'])
            ->setBody($ics)
            ->build();

        $msg = $this->service->parse($part, 'man@netscape.example.com');

        $this->assertNotNull($msg);
        $this->assertSame('REQUEST', $msg->method->value);
        $this->assertSame('man@netscape.example.com', $msg->actorEmail);

        $event = $msg->getFirstEvent();
        $this->assertNotNull($event);
        $this->assertSame('Phone Conference', $event->getSummary());
        $this->assertSame('calsvr.example.com-873970198738777', $event->getUid());
        $this->assertSame('ftp://ftp.bar.example.com/pub/docs/foo.doc', $event->getPropertyValue('ATTACH'));
    }

    /**
     * RFC 6047 §4.2: multipart/alternative with text/plain + text/calendar.
     */
    #[Test]
    public function parseMultipartAlternative(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'PRODID:-//Example/ExampleCalendarClient//EN',
            'METHOD:REQUEST',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'ORGANIZER:mailto:foo1@example.com',
            'ATTENDEE;ROLE=CHAIR;PARTSTAT=ACCEPTED:mailto:foo1@example.com',
            'ATTENDEE;RSVP=YES;CUTYPE=INDIVIDUAL:mailto:foo2@example.com',
            'DTSTAMP:19970611T190000Z',
            'DTSTART:19970701T170000Z',
            'DTEND:19970701T173000Z',
            'SUMMARY:Phone Conference',
            'UID:calsvr.example.com-8739701987387771',
            'SEQUENCE:0',
            'STATUS:CONFIRMED',
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);

        $textPart = PartBuilder::text(
            "When: 7/1/1997 10:00AM PDT - 7/1/97 10:30AM PDT\nOrganizer: foo1@example.com\nSummary: Phone Conference",
            'plain',
            'us-ascii',
        );

        $calPart = (new PartBuilder())
            ->setContentType('text/calendar', ['charset' => 'US-ASCII', 'method' => 'REQUEST'])
            ->setBody($ics);

        $multipart = PartBuilder::multipart('alternative', $textPart, $calPart)->build();

        $msg = $this->service->parse($multipart, 'foo1@example.com');

        $this->assertNotNull($msg);
        $this->assertSame('REQUEST', $msg->method->value);

        $event = $msg->getFirstEvent();
        $this->assertNotNull($event);
        $this->assertSame('Phone Conference', $event->getSummary());
        $this->assertSame('calsvr.example.com-8739701987387771', $event->getUid());
    }

    /**
     * RFC 6047 §4.3: multipart/related with CID-referenced attachment.
     */
    #[Test]
    public function parseMultipartRelatedWithCid(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'PRODID:-//Example/ExampleCalendarClient//EN',
            'METHOD:REQUEST',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'ORGANIZER:mailto:foo1@example.com',
            'ATTENDEE;ROLE=CHAIR;PARTSTAT=ACCEPTED:mailto:foo1@example.com',
            'ATTENDEE;RSVP=YES;CUTYPE=INDIVIDUAL:mailto:foo2@example.com',
            'DTSTAMP:19970611T190000Z',
            'DTSTART:19970701T180000Z',
            'DTEND:19970701T183000Z',
            'SUMMARY:Phone Conference',
            'UID:calsvr.example.com-8739701987387771',
            'ATTACH:cid:123456789@example.com',
            'SEQUENCE:0',
            'STATUS:CONFIRMED',
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);

        $calPart = (new PartBuilder())
            ->setContentType('text/calendar', ['charset' => 'US-ASCII', 'method' => 'REQUEST'])
            ->setBody($ics);

        $attachPart = (new PartBuilder())
            ->setContentType('application/msword')
            ->setContentId('123456789@example.com')
            ->setBody('fake-document-content');

        $multipart = PartBuilder::multipart('related', $calPart, $attachPart)->build();

        $msg = $this->service->parse($multipart, 'foo1@example.com');

        $this->assertNotNull($msg);
        $this->assertSame('REQUEST', $msg->method->value);

        $event = $msg->getFirstEvent();
        $this->assertNotNull($event);
        $this->assertSame('Phone Conference', $event->getSummary());
        $this->assertSame('cid:123456789@example.com', $event->getPropertyValue('ATTACH'));
    }

    /**
     * RFC 6047 §4.4: Multiple similar components in a single PUBLISH.
     */
    #[Test]
    public function parseMultipleSimilarEvents(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'PRODID:-//Example/ExampleCalendarClient//EN',
            'METHOD:PUBLISH',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'ORGANIZER:mailto:foo1@example.com',
            'DTSTAMP:19970611T150000Z',
            'DTSTART:19970701T150000Z',
            'DTEND:19970701T230000Z',
            'SUMMARY:Company Picnic',
            'DESCRIPTION:Food and drink will be provided',
            'UID:calsvr.example.com-873970198738777-1',
            'SEQUENCE:0',
            'STATUS:CONFIRMED',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'ORGANIZER:mailto:foo1@example.com',
            'DTSTAMP:19970611T190000Z',
            'DTSTART:19970715T150000Z',
            'DTEND:19970715T230000Z',
            'SUMMARY:Company Bowling Tournament',
            'DESCRIPTION:We have 10 lanes reserved',
            'UID:calsvr.example.com-873970198738777-2',
            'SEQUENCE:0',
            'STATUS:CONFIRMED',
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);

        $part = (new PartBuilder())
            ->setContentType('text/calendar', ['charset' => 'US-ASCII', 'method' => 'PUBLISH'])
            ->setBody($ics)
            ->build();

        $msg = $this->service->parse($part, 'foo1@example.com');

        $this->assertNotNull($msg);
        $this->assertSame('PUBLISH', $msg->method->value);

        $events = $msg->calendar->getEvents();
        $this->assertCount(2, $events);
        $this->assertSame('Company Picnic', $events[0]->getSummary());
        $this->assertSame('Company Bowling Tournament', $events[1]->getSummary());
    }

    /**
     * RFC 6047 §4.6: Nested multipart/related containing multipart/alternative
     * with text/plain and text/calendar, plus an attachment.
     */
    #[Test]
    public function parseNestedMultipart(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'PRODID:-//Example/ExampleCalendarClient//EN',
            'METHOD:REQUEST',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'ORGANIZER:foo1@example.com',
            'ATTENDEE;ROLE=CHAIR;PARTSTAT=ACCEPTED:foo1@example.com',
            'ATTENDEE;RSVP=YES;CUTYPE=INDIVIDUAL:mailto:foo2@example.com',
            'ATTENDEE;RSVP=YES;CUTYPE=INDIVIDUAL:mailto:foo3@example.com',
            'DTSTAMP:19970611T190000Z',
            'DTSTART:19970621T170000Z',
            'DTEND:19970621T173000Z',
            'SUMMARY:Let\'s discuss the attached document',
            'UID:calsvr.example.com-873970198738777-8aa',
            'ATTACH:cid:calsvr.example.com-12345aaa',
            'SEQUENCE:0',
            'STATUS:CONFIRMED',
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);

        $textPart = PartBuilder::text(
            "When: 7/1/1997 10:00PM PDT\nOrganizer: foo1@example.com\nSummary: Let's discuss the attached document",
            'plain',
            'us-ascii',
        );

        $calPart = (new PartBuilder())
            ->setContentType('text/calendar', ['charset' => 'US-ASCII', 'method' => 'REQUEST'])
            ->setBody($ics);

        $alternativePart = PartBuilder::multipart('alternative', $textPart, $calPart);

        $attachPart = (new PartBuilder())
            ->setContentType('application/msword')
            ->setContentId('calsvr.example.com-12345aaa')
            ->setBody('fake-doc-content');

        $relatedPart = PartBuilder::multipart('related', $alternativePart, $attachPart)->build();

        $msg = $this->service->parse($relatedPart, 'foo1@example.com');

        $this->assertNotNull($msg);
        $this->assertSame('REQUEST', $msg->method->value);

        $event = $msg->getFirstEvent();
        $this->assertNotNull($event);
        $this->assertSame("Let's discuss the attached document", $event->getSummary());
        $this->assertSame('calsvr.example.com-873970198738777-8aa', $event->getUid());
    }
}
