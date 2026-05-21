# Upgrading to horde/imip

## Overview

`horde/imip` is a new package factored out of horde/itip

This package extracts the iTIP-over-email (RFC 6047) transport logic that previously
lived inside `Horde_Itip_Response_*` classes and the `Horde_Itip::sendMultiPartResponse()`
method into a standalone, PSR-14-based architecture.

## Migration from Horde_Itip Response Classes

### Sending (outbound)

**Before (legacy):**
```php
$response = new Horde_Itip_Response_Type_Accept($resource);
$response->setRequest($vevent);
Horde_Itip::sendMultiPartResponse($response, $transport, $address);
```

**After:**
```php
use Horde\Imip\ImipSendingListener;
use Horde\Imip\MessageBuilder;
use Horde\Imip\SimpleSenderIdentity;

// 1. Wire up the listener (once, at bootstrap)
$listener = new ImipSendingListener(
    new MessageBuilder(),
    new SimpleSenderIdentity('organizer@example.com', 'Organizer Name'),
    $transport,
);

// 2. Process the iTIP message through ItipProcessor
$result = $processor->process($message);

// 3. Dispatch the appropriate PSR-14 event
//    The listener builds MIME messages and sends via transport.
$dispatcher->dispatch(new ReplySending($result));
```

### Parsing (inbound)

**Before (legacy):**
```php
// Manual MIME traversal + Horde_Icalendar parsing
$data = $mimePart->getContents();
$ical = new Horde_Icalendar();
$ical->parsevCalendar($data);
```

**After:**
```php
use Horde\Imip\ImipParsingService;

$service = new ImipParsingService();
$itipMessage = $service->parse($mimePart, $senderEmail);
// Returns null if no text/calendar part found
// Returns ItipMessage ready for ItipProcessor::process()
```

## Supported iTIP Methods

The `ImipSendingListener` handles all 8 RFC 5546 methods:

| Method | Event Class | Action Class |
|--------|-------------|--------------|
| REQUEST | `InvitationSending` | `SendRequest` |
| REPLY | `ReplySending` | `SendReply` |
| CANCEL | `CancellationSending` | `SendCancel` |
| PUBLISH | `PublishSending` | `SendPublish` |
| ADD | `AddSending` | `SendAdd` |
| REFRESH | `RefreshSending` | `SendRefresh` |
| COUNTER | `CounterSending` | `SendCounter` |
| DECLINECOUNTER | `DeclineCounterSending` | `SendDeclineCounter` |

## Configuration

Use `ImipOptions` to control message building:

```php
use Horde\Imip\ImipOptions;

$options = new ImipOptions(
    charset: 'UTF-8',           // Character set for calendar parts
    prodId: '-//My App//EN',    // PRODID for generated calendars
    multipart: true,            // Wrap in multipart/alternative with text body
);

$builder = new MessageBuilder($options);
```
