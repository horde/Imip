# Horde iMIP

`horde/imip` is the iMIP (RFC 6047) transport layer for sending and parsing MIME-encapsulated iTIP messages. It bridges `horde/itip`'s decision engine to actual email delivery by listening for PSR-14 scheduling events and constructing RFC-compliant `text/calendar` MIME messages.

## Installation

```bash
composer require horde/imip
```

Requires PHP 8.1+.

## Architecture

### Design Principles

- **Listener-based** — plugs into any PSR-14 dispatcher, no tight coupling
- **Pure PSR-4** — no legacy PSR-0 code, fully typed, readonly value objects
- **Builds on modern horde/mime** — uses `Horde\Mime\Part`, `PartBuilder`, `MessageRenderer`
- **Sends via modern horde/mail** — uses `Horde\Mail\Transport\Transport` interface
- **Bidirectional** — sends outbound iTIP and parses inbound iMIP messages

### Components

| Class | Purpose |
|-------|---------|
| `ImipSendingListener` | PSR-14 listener: handles `*Sending` events, builds and sends MIME |
| `MessageBuilder` | Constructs `ComposedMessage` from VCalendar + sender/recipient info |
| `ImipParsingService` | Extracts `ItipMessage` from incoming MIME `Part` tree |
| `SenderIdentity` | Interface describing the sender (email, name, reply-to) |
| `SimpleSenderIdentity` | Value-object implementation of `SenderIdentity` |
| `ImipOptions` | Configuration: charset, prodId, multipart preference |

### Outbound Flow

```
horde/itip ItipProcessor
    │
    │ App applies ItipResult, then dispatches PSR-14 event
    ▼
PSR-14 EventDispatcher
    │
    ▼
ImipSendingListener
    │  - receives InvitationSending / ReplySending / CancellationSending
    │  - iterates result->actions (SendRequest, SendReply, SendCancel)
    │  - calls MessageBuilder::build() for each recipient
    │  - renders via MessageRenderer and sends via Transport
    ▼
Horde\Mail\Transport\Transport::send()
```

### Inbound Flow

```
Incoming email (raw MIME)
    │
    │ Application parses with Horde\Mime\MimeParser
    ▼
Horde\Mime\Part tree
    │
    ▼
ImipParsingService::parse($part, $actorEmail)
    │  - finds text/calendar part (recursive search)
    │  - parses iCalendar via horde/icalendar
    │  - wraps as ItipMessage
    ▼
ItipMessage → feed into ItipProcessor::process()
```

### Boundaries

This library is responsible for:

- Constructing RFC 6047 MIME messages from VCalendar objects
- Sending iMIP messages via `Horde\Mail\Transport\Transport`
- Listening for PSR-14 scheduling events dispatched after iTIP processing
- Parsing inbound MIME messages to extract `ItipMessage`

This library is **not** responsible for:

- iTIP logic (accept/reject, SEQUENCE handling, conflict detection) — see `horde/itip`
- iCalendar parsing/writing — see `horde/icalendar`
- Mail transport implementation (SMTP, sendmail, etc.) — see `horde/mail`
- MIME structure parsing beyond finding the `text/calendar` part — see `horde/mime`
- Event dispatching — the application calls `$dispatcher->dispatch()`

### Collaboration Partners

| Package | Relationship |
|---------|-------------|
| `horde/itip` | Provides ItipMessage, ItipResult, SendReply/Request/Cancel actions, and PSR-14 event types |
| `horde/icalendar` | Provides VCalendar, Writer for serialization, Reader/AbstractComponent for parsing |
| `horde/mime` | Provides Part, PartBuilder, MessageBuilder, MessageRenderer, MimeParser |
| `horde/mail` | Provides Transport interface, AddressList, Address, MockTransport for testing |
| `horde/eventdispatcher` | PSR-14 dispatcher that routes events to this listener |

## Usage

### Sending (Outbound iMIP)

```php
use Horde\Imip\ImipSendingListener;
use Horde\Imip\MessageBuilder;
use Horde\Imip\SimpleSenderIdentity;
use Horde\Mail\Transport\SmtpTransport;

// Configure
$sender = new SimpleSenderIdentity(
    email: 'calendar@example.com',
    commonName: 'Calendar System',
);
$builder = new MessageBuilder();
$transport = new SmtpTransport(/* config */);

// Register listener with your PSR-14 provider
$listener = new ImipSendingListener($builder, $sender, $transport);
$provider->addListener(ItipEvent::class, $listener);

// After processing iTIP, dispatch the event — listener sends automatically
$result = $processor->process($message);
// ... apply changes ...
$dispatcher->dispatch(new ReplySending($message, $result));
```

### Parsing (Inbound iMIP)

```php
use Horde\Imip\ImipParsingService;
use Horde\Mime\MimeParser;

$service = new ImipParsingService();

// Parse raw email into a Part tree
$part = MimeParser::parse($rawMimeMessage);

// Extract ItipMessage (returns null if no text/calendar part)
$itipMessage = $service->parse($part, 'sender@example.com');

if ($itipMessage !== null) {
    $result = $processor->process($itipMessage);
    // ... handle result ...
}
```

### Custom Sender Identity

Implement `SenderIdentity` for integration with your application's identity system:

```php
use Horde\Imip\SenderIdentity;

class HordeIdentityAdapter implements SenderIdentity
{
    public function __construct(private MyIdentityService $identities) {}

    public function getEmail(): string { return $this->identities->getDefault()->email; }
    public function getCommonName(): string { return $this->identities->getDefault()->fullname; }
    public function getFrom(): string { return sprintf('%s <%s>', $this->getCommonName(), $this->getEmail()); }
    public function getReplyTo(): ?string { return $this->identities->getDefault()->replyTo; }
}
```

## Upgrading

This is a new package — there is no legacy `Horde_Imip` to upgrade from. The iMIP transport logic was previously embedded in `horde/itip`'s `Horde_Itip_Response*` classes (PSR-0 `lib/`). Those classes remain frozen in `horde/itip` for backward compatibility but are not used by this package.

See `horde/itip`'s [doc/UPGRADING.md](../Itip/doc/UPGRADING.md) for guidance on migrating from the legacy combined itip+imip API.

## License

LGPL-2.1 — see [LICENSE](LICENSE).
