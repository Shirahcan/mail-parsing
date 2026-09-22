# shirahcan/mail-parsing

Turns raw MIME into plain values. Every Shirah product that accepts email uses this one.

Lifted out of Portify on 2026-09-22, unchanged. It had been serving production inbound
mail for months; the extraction moved the namespace and nothing else.

```php
use Shirahcan\MailParsing\InboundEmailParser;

$parsed = (new InboundEmailParser)->parse($rawMime);

if ($parsed->isAutoSubmitted()) {
    // A machine wrote this. Accept it, record why, and do not route it.
    return $parsed->automatedReason;   // REASON_NULL_RETURN_PATH, ...
}

$parsed->from;         // lowercased address
$parsed->subject;
$parsed->bodyText;     // quoted history stripped
$parsed->attachments;  // ParsedAttachment[]
```

## ⚠ What this is, and what it deliberately is not

It answers **"what is in this message"**. It has no opinion at all about **"where does it
belong and may it act"** — that is each product's own question, and the answer is
different in each:

| | This package | The product |
|---|---|---|
| MIME, headers, bodies, attachments | ✅ | |
| Quoted-reply stripping | ✅ | |
| Bounce / auto-reply / mailing-list detection | ✅ | |
| Which address kinds exist, reply tokens | | ✅ |
| Which case / client / invoice this belongs to | | ✅ |
| Sender-match, gates, rate limits, storage | | ✅ |

⚠ **Keeping that line is the point of the package.** Portify's `InboundEmailRouter` is 424
lines of Portify, and none of it generalises; the parser is 368 lines of email, and all of
it does. A "shared inbound email" package that swallowed the router would be a package that
no second product could actually use.

⚠ **No keys, no network, no state.** If a future product links a mailbox over OAuth, the
client secrets and refresh tokens belong in a SERVICE, not here — the same split as
`ai-service` (holds every provider key) versus `shirahcan/ai-waterfall` (holds none). This
package would still be the thing that parses what that service fetches.

## ⚠ The judgement calls, which are the whole value

These were paid for in production incidents. Do not soften them without a reason.

* **`zbateson/mail-mime-parser`, not `php-mime-mail-parser`.** The latter needs the
  `mailparse` PHP extension, which a stock PHP build does not ship. Requiring an extension
  on every host that runs a consuming product, to avoid a composer dependency, is not a
  trade worth making.

* **A false positive in `automatedReason()` is worse than a false negative.** It withholds
  mail from a conversation. A missed bounce is noise in an admin queue; an eaten reply is a
  client who believes they answered and a consultant who believes they did not. Every rule
  is either an explicit machine-only header, or a subject heuristic anchored hard enough
  that a human sentence cannot trip it.

* **`Return-Path: <>` is read RAW.** It is RFC 5321's definitive bounce marker and the
  highest-value signal here. An address-aware accessor returns an empty string for `<>`,
  which is indistinguishable from the header being absent — and that distinction is the
  entire signal. It was missing until 2026-09-03, so hard bounces reached the admin queue
  looking like a person had written them.

* **Subject heuristics exist because Exchange omits `Auto-Submitted`.** Out-of-office
  replies have shipped without it in common configurations, so header-only detection misses
  the single most frequent auto-reply in business email.

* **Quote stripping is conservative, and falls back to the original.** If stripping consumed
  everything, the markers were wrong about that message — returning empty would make it look
  like the person sent nothing.

* **`mimeType` is whatever the sender claimed.** `ParsedAttachment::$content` is bytes from
  the open internet. Never serve, link or execute on the strength of that value; scan the
  bytes. documents-service does this now.

## Tests

`vendor/bin/phpunit`. They are the parser's original Portify tests, moved with it.
