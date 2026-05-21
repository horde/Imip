<?php

declare(strict_types=1);

namespace Horde\Imip\Test\Unit;

use Horde\Icalendar\Calendar\VCalendar;
use Horde\Icalendar\Calendar\Vevent;
use Horde\Icalendar\Enum\CalendarMethod;
use Horde\Imip\ImipOptions;
use Horde\Imip\MessageBuilder;
use Horde\Imip\SimpleSenderIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageBuilder::class)]
#[CoversClass(ImipOptions::class)]
#[CoversClass(SimpleSenderIdentity::class)]
final class MessageBuilderTest extends TestCase
{
    private function createCalendar(string $method = 'REQUEST'): VCalendar
    {
        $cal = new VCalendar();
        $cal->setVersion();
        $cal->setProdid('-//Test//Test//EN');
        $cal->setMethod(CalendarMethod::from($method));

        $event = new Vevent();
        $event->setUid('test-uid-123@example.com');
        $event->setSummary('Team Meeting');
        $cal->addChild($event);

        return $cal;
    }

    private function createSender(): SimpleSenderIdentity
    {
        return new SimpleSenderIdentity(
            email: 'organizer@example.com',
            commonName: 'Test Organizer',
        );
    }

    #[Test]
    public function buildProducesComposedMessageWithCalendarPart(): void
    {
        $builder = new MessageBuilder();
        $composed = $builder->build(
            $this->createCalendar(),
            CalendarMethod::from('REQUEST'),
            $this->createSender(),
            'attendee@example.com',
            'Invitation: Team Meeting',
        );

        $this->assertSame('text/calendar', $composed->part->fullType());
        $this->assertStringContains('BEGIN:VCALENDAR', $composed->part->bodyString());
        $this->assertStringContains('METHOD:REQUEST', $composed->part->bodyString());
    }

    #[Test]
    public function buildIncludesRecipientInAddressList(): void
    {
        $builder = new MessageBuilder();
        $composed = $builder->build(
            $this->createCalendar(),
            CalendarMethod::from('REQUEST'),
            $this->createSender(),
            'attendee@example.com',
            'Invitation: Team Meeting',
        );

        $addresses = $composed->recipients->bareAddresses();
        $this->assertContains('attendee@example.com', $addresses);
    }

    #[Test]
    public function buildSetsSubjectHeader(): void
    {
        $builder = new MessageBuilder();
        $composed = $builder->build(
            $this->createCalendar(),
            CalendarMethod::from('REQUEST'),
            $this->createSender(),
            'attendee@example.com',
            'Invitation: Team Meeting',
        );

        $subject = $composed->headers->subject();
        $this->assertNotNull($subject);
        $this->assertSame('Invitation: Team Meeting', $subject->value());
    }

    #[Test]
    public function buildWithTextBodyProducesMultipartAlternative(): void
    {
        $builder = new MessageBuilder();
        $composed = $builder->build(
            $this->createCalendar(),
            CalendarMethod::from('REQUEST'),
            $this->createSender(),
            'attendee@example.com',
            'Invitation: Team Meeting',
            'You are invited to Team Meeting.',
        );

        $this->assertTrue($composed->part->isMultipart());
        $this->assertSame('multipart/alternative', $composed->part->fullType());
        $this->assertCount(2, $composed->part->children);

        $textPart = $composed->part->children[0];
        $calPart = $composed->part->children[1];

        $this->assertSame('text/plain', $textPart->fullType());
        $this->assertSame('text/calendar', $calPart->fullType());
    }

    #[Test]
    public function buildWithoutMultipartOptionSkipsTextBody(): void
    {
        $options = new ImipOptions(multipart: false);
        $builder = new MessageBuilder($options);

        $composed = $builder->build(
            $this->createCalendar(),
            CalendarMethod::from('REQUEST'),
            $this->createSender(),
            'attendee@example.com',
            'Invitation: Team Meeting',
            'Text body that should be ignored.',
        );

        $this->assertSame('text/calendar', $composed->part->fullType());
    }

    #[Test]
    public function buildSetsReplyToWhenDifferentFromFrom(): void
    {
        $sender = new SimpleSenderIdentity(
            email: 'organizer@example.com',
            commonName: 'Test Organizer',
            replyTo: 'noreply@example.com',
        );

        $builder = new MessageBuilder();
        $composed = $builder->build(
            $this->createCalendar(),
            CalendarMethod::from('REQUEST'),
            $sender,
            'attendee@example.com',
            'Invitation: Team Meeting',
        );

        $replyTo = $composed->headers->get('reply-to');
        $this->assertNotNull($replyTo);
        $this->assertSame('noreply@example.com', $replyTo->value());
    }

    #[Test]
    public function buildSetsFromHeader(): void
    {
        $builder = new MessageBuilder();
        $composed = $builder->build(
            $this->createCalendar(),
            CalendarMethod::from('REQUEST'),
            $this->createSender(),
            'attendee@example.com',
            'Invitation: Team Meeting',
        );

        $from = $composed->headers->get('from');
        $this->assertNotNull($from);
        $this->assertStringContains('organizer@example.com', $from->value());
    }

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            sprintf('Failed asserting that "%s" contains "%s"', $haystack, $needle),
        );
    }
}
