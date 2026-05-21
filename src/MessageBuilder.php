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
use Horde\Icalendar\Writer;
use Horde\Mail\Rfc822\Address;
use Horde\Mail\Rfc822\AddressList;
use Horde\Mime\ComposedMessage;
use Horde\Mime\PartBuilder;

/**
 * Builds RFC 6047-compliant MIME messages from VCalendar objects.
 *
 * Produces a ComposedMessage (Part + HeaderCollection + AddressList)
 * ready for rendering and sending via Horde\Mail\Transport\Transport.
 */
final class MessageBuilder
{
    public function __construct(
        private ImipOptions $options = new ImipOptions(),
    ) {}

    /**
     * Build a composed message from a VCalendar and sender/recipient info.
     */
    public function build(
        VCalendar $calendar,
        CalendarMethod $method,
        SenderIdentity $sender,
        string $recipientEmail,
        string $subject,
        ?string $textBody = null,
    ): ComposedMessage {
        $writer = new Writer();
        $icsContent = $writer->writeString($calendar);

        $icsPart = (new PartBuilder())
            ->setContentType('text/calendar', [
                'charset' => $this->options->charset,
                'method' => $method->value,
            ])
            ->setFilename('event.ics')
            ->setBody($icsContent);

        if ($this->options->multipart && $textBody !== null) {
            $textPart = PartBuilder::text($textBody, 'plain', $this->options->charset);
            $message = PartBuilder::multipart('alternative', $textPart, $icsPart)->build();
        } else {
            $message = $icsPart->build();
        }

        $recipientAddress = $this->parseAddress($recipientEmail);
        $recipients = AddressList::addressesOnly($recipientAddress);

        $builder = new \Horde\Mime\MessageBuilder();
        $builder->setFrom($sender->getFrom())
            ->setTo($recipientEmail)
            ->setSubject($subject)
            ->setBasePart($message);

        $replyTo = $sender->getReplyTo();
        if ($replyTo !== null && $replyTo !== $sender->getFrom()) {
            $builder->setReplyTo($replyTo);
        }

        return $builder->build();
    }

    /**
     * Parse an email address string into an Address object.
     */
    private function parseAddress(string $email): Address
    {
        $parts = explode('@', $email, 2);

        return new Address(
            mailbox: $parts[0],
            host: $parts[1] ?? null,
        );
    }
}
