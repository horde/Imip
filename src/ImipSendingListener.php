<?php

declare(strict_types=1);

/**
 * Copyright 2002-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @author    Mike Cochrane <mike@graftonhall.co.nz>
 * @author    Chuck Hagenbuch <chuck@horde.org>
 * @author    Steffen Hansen <steffen@klaralvdalens-datakonsult.se>
 * @author    Gunnar Wrobel <wrobel@pardus.de>
 * @author    Michael J Rubinsky <mrubinsk@horde.org>
 * @author    Ralf Lang <ralf.lang@ralf-lang.de>
 * @category  Horde
 * @copyright 2002-2026 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Imip
 */

namespace Horde\Imip;

use Horde\Icalendar\Calendar\VCalendar;
use Horde\Icalendar\Enum\CalendarMethod;
use Horde\Imip\Exception\ImipException;
use Horde\Itip\Action\SendAdd;
use Horde\Itip\Action\SendCancel;
use Horde\Itip\Action\SendCounter;
use Horde\Itip\Action\SendDeclineCounter;
use Horde\Itip\Action\SendPublish;
use Horde\Itip\Action\SendRefresh;
use Horde\Itip\Action\SendReply;
use Horde\Itip\Action\SendRequest;
use Horde\Itip\Event\AddSending;
use Horde\Itip\Event\CancellationSending;
use Horde\Itip\Event\CounterSending;
use Horde\Itip\Event\DeclineCounterSending;
use Horde\Itip\Event\InvitationSending;
use Horde\Itip\Event\ItipEvent;
use Horde\Itip\Event\PublishSending;
use Horde\Itip\Event\RefreshSending;
use Horde\Itip\Event\ReplySending;
use Horde\Mail\Transport\Transport;
use Horde\Mail\Transport\TransportException;
use Horde\Mime\ComposedMessage;
use Horde\Mime\MessageRenderer;

/**
 * PSR-14 listener that handles outgoing iTIP scheduling events
 * by building MIME messages and sending them via mail transport.
 *
 * Register with SimpleListenerProvider — type-hinted to ItipEvent base class
 * so it receives all *Sending subtypes.
 */
final class ImipSendingListener
{
    public function __construct(
        private MessageBuilder $builder,
        private SenderIdentity $sender,
        private Transport $transport,
    ) {}

    /**
     * Handle an outgoing iTIP scheduling event by dispatching its actions via email.
     */
    public function __invoke(ItipEvent $event): void
    {
        if (!($event instanceof InvitationSending)
            && !($event instanceof ReplySending)
            && !($event instanceof CancellationSending)
            && !($event instanceof PublishSending)
            && !($event instanceof AddSending)
            && !($event instanceof RefreshSending)
            && !($event instanceof CounterSending)
            && !($event instanceof DeclineCounterSending)
        ) {
            return;
        }

        foreach ($event->result->actions as $action) {
            match (true) {
                $action instanceof SendReply => $this->sendReply($action),
                $action instanceof SendRequest => $this->sendRequest($action),
                $action instanceof SendCancel => $this->sendCancel($action),
                $action instanceof SendPublish => $this->sendPublish($action),
                $action instanceof SendAdd => $this->sendAdd($action),
                $action instanceof SendRefresh => $this->sendRefresh($action),
                $action instanceof SendCounter => $this->sendCounter($action),
                $action instanceof SendDeclineCounter => $this->sendDeclineCounter($action),
                default => null,
            };
        }
    }

    /**
     * Send a REPLY message to the organizer.
     */
    private function sendReply(SendReply $action): void
    {
        $summary = $this->extractSummary($action->replyCalendar);
        $subject = sprintf('Re: %s', $summary);

        $composed = $this->builder->build(
            $action->replyCalendar,
            CalendarMethod::from('REPLY'),
            $this->sender,
            $action->organizerEmail,
            $subject,
        );

        $this->send($composed);
    }

    /**
     * Send a REQUEST message to attendees.
     */
    private function sendRequest(SendRequest $action): void
    {
        $summary = $this->extractSummary($action->requestCalendar);
        $subject = sprintf('Invitation: %s', $summary);

        foreach ($action->attendeeEmails as $email) {
            $composed = $this->builder->build(
                $action->requestCalendar,
                CalendarMethod::from('REQUEST'),
                $this->sender,
                $email,
                $subject,
            );

            $this->send($composed);
        }
    }

    /**
     * Send a CANCEL message to attendees.
     */
    private function sendCancel(SendCancel $action): void
    {
        $summary = $this->extractSummary($action->cancelCalendar);
        $subject = sprintf('Cancelled: %s', $summary);

        foreach ($action->attendeeEmails as $email) {
            $composed = $this->builder->build(
                $action->cancelCalendar,
                CalendarMethod::from('CANCEL'),
                $this->sender,
                $email,
                $subject,
            );

            $this->send($composed);
        }
    }

    /**
     * Send a PUBLISH message to subscribers.
     */
    private function sendPublish(SendPublish $action): void
    {
        $summary = $this->extractSummary($action->publishCalendar);
        $subject = sprintf('Published: %s', $summary);

        foreach ($action->subscriberEmails as $email) {
            $composed = $this->builder->build(
                $action->publishCalendar,
                CalendarMethod::from('PUBLISH'),
                $this->sender,
                $email,
                $subject,
            );

            $this->send($composed);
        }
    }

    /**
     * Send an ADD message (new instances) to attendees.
     */
    private function sendAdd(SendAdd $action): void
    {
        $summary = $this->extractSummary($action->addCalendar);
        $subject = sprintf('Updated: %s', $summary);

        foreach ($action->attendeeEmails as $email) {
            $composed = $this->builder->build(
                $action->addCalendar,
                CalendarMethod::from('ADD'),
                $this->sender,
                $email,
                $subject,
            );

            $this->send($composed);
        }
    }

    /**
     * Send a REFRESH request to the organizer.
     */
    private function sendRefresh(SendRefresh $action): void
    {
        $summary = '(Refresh request)';
        $subject = sprintf('Refresh: %s', $summary);

        $composed = $this->builder->build(
            $action->refreshCalendar,
            CalendarMethod::from('REFRESH'),
            $this->sender,
            $action->organizerEmail,
            $subject,
        );

        $this->send($composed);
    }

    /**
     * Send a COUNTER proposal to the organizer.
     */
    private function sendCounter(SendCounter $action): void
    {
        $summary = $this->extractSummary($action->counterCalendar);
        $subject = sprintf('Counter-proposal: %s', $summary);

        $composed = $this->builder->build(
            $action->counterCalendar,
            CalendarMethod::from('COUNTER'),
            $this->sender,
            $action->organizerEmail,
            $subject,
        );

        $this->send($composed);
    }

    /**
     * Send a DECLINECOUNTER rejection to the attendee.
     */
    private function sendDeclineCounter(SendDeclineCounter $action): void
    {
        $summary = $this->extractSummary($action->declineCounterCalendar);
        $subject = sprintf('Declined: %s', $summary);

        $composed = $this->builder->build(
            $action->declineCounterCalendar,
            CalendarMethod::from('DECLINECOUNTER'),
            $this->sender,
            $action->attendeeEmail,
            $subject,
        );

        $this->send($composed);
    }

    /**
     * Extract the event summary for use in email subject lines.
     */
    private function extractSummary(VCalendar $calendar): string
    {
        $events = $calendar->getEvents();
        if ($events !== []) {
            return $events[0]->getSummary() ?? '(No subject)';
        }
        $todos = $calendar->getTodos();
        if ($todos !== []) {
            return $todos[0]->getSummary() ?? '(No subject)';
        }
        return '(No subject)';
    }

    /**
     * Render and send a composed MIME message via transport.
     */
    private function send(ComposedMessage $composed): void
    {
        try {
            $headers = MessageRenderer::headersToArray($composed->part, $composed->headers);
            $body = MessageRenderer::renderBody($composed->part);
            $this->transport->send($composed->recipients, $headers, $body);
        } catch (TransportException $e) {
            throw new ImipException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
