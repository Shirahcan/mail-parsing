<?php

namespace Shirahcan\MailParsing;

use ZBateson\MailMimeParser\Message as MimeMessage;

/**
 * Turns raw MIME into the plain values a product stores.
 *
 * ⚠ USES `zbateson/mail-mime-parser` (pure PHP) AND NOT `php-mime-mail-parser`.
 * The latter needs the `mailparse` PHP extension, which a stock PHP build does
 * not ship. Requiring an extension on every host that runs a consuming product,
 * purely to avoid a composer dependency, is not a trade worth making.
 */
class InboundEmailParser
{
    /**
     * Markers that begin a quoted reply history.
     *
     * Deliberately CONSERVATIVE. Over-eager stripping silently eats the sender's
     * real words, and nobody notices until a client insists they answered a
     * question that appears blank in the thread. Anything not matched here just
     * stays in the body, which is the safe direction to be wrong.
     */
    private const QUOTE_MARKERS = [
        '/^-{2,}\s*Original Message\s*-{2,}$/mi',
        '/^_{10,}$/m',
        '/^On .{4,120}\bwrote:\s*$/mi',
        '/^From:\s.+\bSent:\s/mis',
    ];

    public function parse(string $raw): ParsedInboundEmail
    {
        $message = MimeMessage::from($raw, false);

        $text = $message->getTextContent();
        $html = $message->getHtmlContent();

        $attachments = [];

        foreach ($message->getAllAttachmentParts() as $part) {
            $filename = $part->getFilename();

            // A part with no filename is usually an inline alternative body, not
            // something a human attached. Skipping keeps "2 attachments" meaning
            // what the sender thinks it means.
            if ($filename === null || trim($filename) === '') {
                continue;
            }

            $content = (string) $part->getContent();

            $attachments[] = new ParsedAttachment(
                filename: $this->safeFilename($filename),
                mimeType: (string) ($part->getContentType() ?: 'application/octet-stream'),
                sizeBytes: strlen($content),
                content: $content,
            );
        }

        return new ParsedInboundEmail(
            messageId: $this->normaliseMessageId($this->header($message, 'Message-ID')),
            inReplyTo: $this->normaliseMessageId($this->header($message, 'In-Reply-To')),
            references: $this->header($message, 'References'),
            to: $this->header($message, 'To') ?? '',
            from: $this->addressOf($message, 'From'),
            fromName: $this->nameOf($message, 'From'),
            subject: $this->header($message, 'Subject'),
            bodyTextOriginal: $text,
            bodyText: $text === null ? null : $this->stripQuotedReply($text),
            bodyHtml: $html === null ? null : $this->stripQuotedReplyHtml($html),
            attachments: $attachments,
            automatedReason: $this->automatedReason($message),
        );
    }

    /**
     * WHY does this look like a machine talking, rather than a person?
     *
     * Returns null for a human, or one of ParsedInboundEmail::REASON_*.
     *
     * Out-of-office replies, bounces and mailing-list traffic must be ACCEPTED
     * and discarded, never bounced. Bouncing an auto-reply is how two mail
     * systems end up talking to each other forever.
     *
     * ⚠ A FALSE POSITIVE HERE IS WORSE THAN A FALSE NEGATIVE. This method causes
     * mail to be withheld from a conversation. A missed bounce is noise in an
     * admin queue; an eaten reply is a client who believes they answered and a
     * consultant who believes they did not. Every rule below is therefore either
     * an explicit machine-only header, or (for the subject heuristics) anchored
     * hard enough that a human sentence cannot trip it.
     */
    private function automatedReason(MimeMessage $message): ?string
    {
        // ── RFC 3834: the sender told us outright ────────────────────────────
        $autoSubmitted = strtolower(trim((string) $this->header($message, 'Auto-Submitted')));

        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return ParsedInboundEmail::REASON_AUTO_SUBMITTED;
        }

        foreach (['X-Autoreply', 'X-Autorespond'] as $header) {
            if ($this->header($message, $header) !== null) {
                return ParsedInboundEmail::REASON_AUTO_SUBMITTED;
            }
        }

        // ── Bounces / DSNs ───────────────────────────────────────────────────
        if ($this->hasNullReturnPath($message)) {
            return ParsedInboundEmail::REASON_NULL_RETURN_PATH;
        }

        if ($this->isDeliveryStatusReport($message)) {
            return ParsedInboundEmail::REASON_DELIVERY_REPORT;
        }

        if ($this->header($message, 'X-Failed-Recipients') !== null) {
            return ParsedInboundEmail::REASON_FAILED_RECIPIENTS;
        }

        // ── Mailing lists / bulk ─────────────────────────────────────────────
        $precedence = strtolower(trim((string) $this->header($message, 'Precedence')));

        if (in_array($precedence, ['bulk', 'junk', 'list', 'auto_reply'], true)) {
            return ParsedInboundEmail::REASON_MAILING_LIST;
        }

        foreach (['List-Id', 'List-Unsubscribe'] as $header) {
            if ($this->header($message, $header) !== null) {
                return ParsedInboundEmail::REASON_MAILING_LIST;
            }
        }

        // ── Last resort: the subject ─────────────────────────────────────────
        if ($this->hasAutomatedSubject($message)) {
            return ParsedInboundEmail::REASON_SUBJECT_HEURISTIC;
        }

        return null;
    }

    /**
     * `Return-Path: <>` — the null envelope sender.
     *
     * ⚠ RFC 5321's DEFINITIVE bounce marker, and the single highest-value signal
     * here: every well-formed DSN carries it, and no legitimate human message
     * does. It was missing entirely before 2026-09-03, so hard bounces reached
     * the admin queue looking like a person had written them.
     *
     * ⚠ Read the RAW value, not the parsed one. `<>` contains no address, so an
     * address-aware accessor returns an empty string for it - which is
     * indistinguishable from the header being absent. That distinction is the
     * whole signal.
     */
    private function hasNullReturnPath(MimeMessage $message): bool
    {
        $header = $message->getHeader('Return-Path');

        if ($header === null) {
            return false;
        }

        $raw = trim((string) $header->getRawValue());

        return $raw === '<>' || $raw === '';
    }

    /** RFC 3464: the formal delivery-status report body. */
    private function isDeliveryStatusReport(MimeMessage $message): bool
    {
        $contentType = strtolower((string) $message->getHeaderValue('Content-Type'));

        if ($contentType !== 'multipart/report') {
            return false;
        }

        $reportType = strtolower(trim((string) $message->getHeaderParameter('Content-Type', 'report-type')));

        // `delivery-status` is a bounce; `disposition-notification` is a read
        // receipt. Both are machines, neither should reach a conversation.
        return in_array($reportType, ['delivery-status', 'disposition-notification'], true);
    }

    /**
     * Subject patterns, for responders that set no header at all.
     *
     * ⚠ NEEDED because Microsoft Exchange out-of-office replies have shipped
     * WITHOUT `Auto-Submitted` in common configurations, so header-only
     * detection misses the single most frequent auto-reply in business email.
     *
     * ⚠ ANCHORED AT THE START on purpose. A real reply titled
     * "Re: our automatic reply setup" must NOT be eaten, so these only match
     * when the phrase opens the subject, optionally behind Re:/Fwd:.
     */
    private function hasAutomatedSubject(MimeMessage $message): bool
    {
        $subject = trim((string) $this->header($message, 'Subject'));

        if ($subject === '') {
            return false;
        }

        $patterns = [
            '/^(?:re:\s*|fwd?:\s*)*out of (?:the )?office\b/i',
            '/^(?:re:\s*|fwd?:\s*)*automatic(?:ally)? repl(?:y|ies)\b/i',
            '/^(?:re:\s*|fwd?:\s*)*auto(?:matic)?[-\s]?response\b/i',
            '/^undeliverable\b/i',
            '/^delivery status notification\b/i',
            '/^(?:mail )?delivery (?:has )?failed\b/i',
            '/^returned mail\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $subject) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Remove the quoted history, keeping everything before the first marker. */
    public function stripQuotedReply(string $text): string
    {
        // ⚠ Hold the ORIGINAL. The fallback at the bottom needs the pre-strip
        // text, and reading it back off $text after stripping returns the empty
        // string we are trying to avoid.
        $original = $text;

        $earliest = null;

        foreach (self::QUOTE_MARKERS as $pattern) {
            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE) === 1) {
                $offset = $m[0][1];
                $earliest = $earliest === null ? $offset : min($earliest, $offset);
            }
        }

        if ($earliest !== null) {
            $text = substr($text, 0, $earliest);
        }

        // Trailing '>' quote block, only when it runs to the end of the message.
        $text = preg_replace('/(?:^>.*$\R?)+\z/m', '', $text) ?? $text;

        $trimmed = trim($text);

        // ⚠ If stripping consumed everything, the markers were wrong about this
        // message - a reply that opens with the quote, say. Returning empty makes
        // it look like the person sent nothing at all, so hand back the whole
        // original and let a human read it.
        return $trimmed === '' ? trim($original) : $trimmed;
    }

    /**
     * The HTML equivalent of stripQuotedReply().
     *
     * Mail clients mark the quoted thread with a small set of well-known
     * containers. Cutting at the FIRST one keeps the reply and drops the history,
     * which is what makes a formatted reply readable in a conversation instead of
     * dragging the entire thread in behind it.
     *
     * ⚠ Same conservative bias as the plain-text version: an unrecognised client
     * just leaves its quote in, which is far better than eating the reply. And if
     * a cut leaves nothing, the original is returned untouched.
     */
    public function stripQuotedReplyHtml(string $html): string
    {
        $markers = [
            // Gmail, and anything that copied Gmail (most webmail).
            '/<div[^>]*class="[^"]*gmail_quote[^"]*"/i',
            // Apple Mail / generic "On … wrote:" wrapper.
            '/<div[^>]*class="[^"]*(?:moz-cite-prefix|yahoo_quoted)[^"]*"/i',
            // Outlook's horizontal rule between reply and history.
            '/<div[^>]*(?:id|class)="[^"]*(?:divRplyFwdMsg|OLK_SRC_BODY_SECTION)[^"]*"/i',
            '/<hr[^>]*(?:id|class)="[^"]*(?:stopSpelling|divRplyFwdMsg)[^"]*"/i',
            // A blockquote that is the conventional quote wrapper.
            '/<blockquote[^>]*(?:type="cite"|class="[^"]*gmail_quote[^"]*")/i',
        ];

        $earliest = null;

        foreach ($markers as $pattern) {
            if (preg_match($pattern, $html, $m, PREG_OFFSET_CAPTURE) === 1) {
                $offset = $m[0][1];
                $earliest = $earliest === null ? $offset : min($earliest, $offset);
            }
        }

        if ($earliest === null) {
            return $html;
        }

        $cut = substr($html, 0, $earliest);

        // strip_tags only to test for REAL content: a cut that leaves nothing but
        // empty markup would render as a blank message.
        return trim(strip_tags($cut)) === '' ? $html : $cut;
    }

    /**
     * Canonical form of a Message-ID / In-Reply-To value: no angle brackets.
     *
     * ⚠ These MUST be normalised consistently. Dedup compares our stored
     * `message_id_header` against an incoming one, and threading compares an
     * incoming `In-Reply-To` against ids we sent. Senders vary in whether the
     * brackets survive, so storing them raw means the same message compares
     * unequal to itself and gets ingested twice.
     */
    private function normaliseMessageId(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        $value = trim($value, '<>');
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Strip any path from a sender-supplied filename.
     *
     * The name comes from the open internet and is later used to build a storage
     * path. `basename()` alone leaves Windows separators intact, so both are
     * normalised first.
     */
    private function safeFilename(string $filename): string
    {
        $filename = str_replace('\\', '/', $filename);
        $filename = basename($filename);
        $filename = preg_replace('/[\x00-\x1F\x7F]/', '', $filename) ?? $filename;
        $filename = trim($filename, ". \t\n\r\0\x0B");

        return $filename === '' ? 'attachment' : mb_substr($filename, 0, 200);
    }

    private function header(MimeMessage $message, string $name): ?string
    {
        $header = $message->getHeader($name);

        if ($header === null) {
            return null;
        }

        $value = trim((string) $header->getValue());

        return $value === '' ? null : $value;
    }

    private function addressOf(MimeMessage $message, string $name): string
    {
        $address = $message->getHeader($name)?->getAddresses()[0] ?? null;

        return strtolower(trim((string) ($address?->getEmail() ?? '')));
    }

    private function nameOf(MimeMessage $message, string $name): ?string
    {
        $address = $message->getHeader($name)?->getAddresses()[0] ?? null;
        $value = trim((string) ($address?->getName() ?? ''));

        return $value === '' ? null : $value;
    }
}
