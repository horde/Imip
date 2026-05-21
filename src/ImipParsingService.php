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
use Horde\Icalendar\Component\AbstractComponent;
use Horde\Imip\Exception\ImipException;
use Horde\Itip\ItipMessage;
use Horde\Mime\Part;
use Throwable;

/**
 * Extracts an ItipMessage from an incoming MIME message.
 *
 * Locates the text/calendar part, parses it via horde/icalendar,
 * and wraps it in an ItipMessage for feeding into ItipProcessor::process().
 */
final class ImipParsingService
{
    /**
     * @param Part   $message      The incoming MIME message (parsed Part tree)
     * @param string $actorEmail   The email address of the actor (sender of the iTIP message)
     *
     * @return ItipMessage|null  Returns null if no text/calendar part is found
     *
     * @throws ImipException  If the calendar data cannot be parsed or has no METHOD
     */
    public function parse(Part $message, string $actorEmail): ?ItipMessage
    {
        $calendarPart = $this->findCalendarPart($message);
        if ($calendarPart === null) {
            return null;
        }

        $icsContent = $calendarPart->bodyString();
        if ($icsContent === '') {
            return null;
        }

        try {
            $component = AbstractComponent::fromString($icsContent);
        } catch (Throwable $e) {
            throw new ImipException('Failed to parse iCalendar data: ' . $e->getMessage(), 0, $e);
        }

        if (!$component instanceof VCalendar) {
            throw new ImipException('MIME text/calendar part does not contain a VCALENDAR');
        }

        try {
            return ItipMessage::fromCalendar($component, $actorEmail);
        } catch (Throwable $e) {
            throw new ImipException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Recursively search for a text/calendar MIME part.
     */
    private function findCalendarPart(Part $part): ?Part
    {
        if ($part->fullType() === 'text/calendar') {
            return $part;
        }

        if ($part->isMultipart()) {
            foreach ($part->children as $child) {
                $found = $this->findCalendarPart($child);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
