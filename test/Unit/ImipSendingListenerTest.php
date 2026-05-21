<?php

declare(strict_types=1);

namespace Horde\Imip\Test\Unit;

use Horde\Icalendar\Calendar\VCalendar;
use Horde\Icalendar\Calendar\Vevent;
use Horde\Icalendar\Enum\CalendarMethod;
use Horde\Imip\ImipSendingListener;
use Horde\Imip\MessageBuilder;
use Horde\Imip\SimpleSenderIdentity;
use Horde\Itip\Action\SendCancel;
use Horde\Itip\Action\SendReply;
use Horde\Itip\Action\SendRequest;
use Horde\Itip\Event\CancellationSending;
use Horde\Itip\Event\InvitationSending;
use Horde\Itip\Event\ReplySending;
use Horde\Itip\ItipMessage;
use Horde\Itip\ItipResult;
use Horde\Mail\Transport\MockTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImipSendingListener::class)]
final class ImipSendingListenerTest extends TestCase
{
    private MockTransport $transport;
    private ImipSendingListener $listener;

    protected function setUp(): void
    {
        $this->transport = new MockTransport();
        $sender = new SimpleSenderIdentity(
            email: 'user@example.com',
            commonName: 'Test User',
        );
        $builder = new MessageBuilder();
        $this->listener = new ImipSendingListener($builder, $sender, $this->transport);
    }

    private function createCalendar(string $method, string $summary = 'Test Event'): VCalendar
    {
        $cal = new VCalendar();
        $cal->setVersion();
        $cal->setProdid('-//Test//Test//EN');
        $cal->setMethod(CalendarMethod::from($method));

        $event = new Vevent();
        $event->setUid('uid-' . uniqid() . '@example.com');
        $event->setSummary($summary);
        $cal->addChild($event);

        return $cal;
    }

    private function createMessage(string $method): ItipMessage
    {
        $cal = $this->createCalendar($method);
        return ItipMessage::fromCalendar($cal, 'sender@example.com');
    }

    #[Test]
    public function sendReplyDeliversToOrganizer(): void
    {
        $cal = $this->createCalendar('REPLY', 'Team Standup');
        $action = new SendReply(
            organizerEmail: 'organizer@example.com',
            attendeeEmail: 'user@example.com',
            replyCalendar: $cal,
        );

        $result = new ItipResult(actions: [$action]);
        $event = new ReplySending($this->createMessage('REPLY'), $result);

        ($this->listener)($event);

        $sent = $this->transport->sentMessages();
        $this->assertCount(1, $sent);
        $this->assertContains('organizer@example.com', $sent[0]->recipients);
        $this->assertStringContains('BEGIN:VCALENDAR', $sent[0]->body);
    }

    #[Test]
    public function sendRequestDeliversToAllAttendees(): void
    {
        $cal = $this->createCalendar('REQUEST', 'Sprint Planning');
        $action = new SendRequest(
            organizerEmail: 'user@example.com',
            attendeeEmails: ['alice@example.com', 'bob@example.com'],
            requestCalendar: $cal,
        );

        $result = new ItipResult(actions: [$action]);
        $event = new InvitationSending($this->createMessage('REQUEST'), $result);

        ($this->listener)($event);

        $sent = $this->transport->sentMessages();
        $this->assertCount(2, $sent);
        $this->assertContains('alice@example.com', $sent[0]->recipients);
        $this->assertContains('bob@example.com', $sent[1]->recipients);
    }

    #[Test]
    public function sendCancelDeliversToAllAttendees(): void
    {
        $cal = $this->createCalendar('CANCEL', 'Cancelled Meeting');
        $action = new SendCancel(
            organizerEmail: 'user@example.com',
            attendeeEmails: ['carol@example.com'],
            cancelCalendar: $cal,
        );

        $result = new ItipResult(actions: [$action]);
        $event = new CancellationSending($this->createMessage('CANCEL'), $result);

        ($this->listener)($event);

        $sent = $this->transport->sentMessages();
        $this->assertCount(1, $sent);
        $this->assertContains('carol@example.com', $sent[0]->recipients);
        $this->assertStringContains('BEGIN:VCALENDAR', $sent[0]->body);
    }

    #[Test]
    public function listenerIgnoresNonSendingEvents(): void
    {
        $event = new \Horde\Itip\Event\InvitationReceived(
            $this->createMessage('REQUEST'),
            new ItipResult(),
        );

        ($this->listener)($event);

        $this->assertCount(0, $this->transport->sentMessages());
    }

    #[Test]
    public function noActionsProducesNoMessages(): void
    {
        $result = new ItipResult(actions: []);
        $event = new ReplySending($this->createMessage('REPLY'), $result);

        ($this->listener)($event);

        $this->assertCount(0, $this->transport->sentMessages());
    }

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            sprintf('Failed asserting that string contains "%s"', $needle),
        );
    }
}
