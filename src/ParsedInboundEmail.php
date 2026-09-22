<?php

namespace Shirahcan\MailParsing;

/**
 * The plain result of parsing raw MIME. No behaviour, no persistence.
 *
 * @property-read ParsedAttachment[] $attachments
 */
class ParsedInboundEmail
{
    /**
     * Why a message was judged to be a machine talking, one constant per signal.
     *
     * ⚠ These are STORED in `inbound_emails.rejection_reason` and rendered to
     * admins, so they are part of the contract, not private detail. A bare
     * boolean was not enough: "we discarded this" with no reason gives an admin
     * no way to tell a legitimate bounce from a real reply we ate by mistake.
     */
    public const REASON_AUTO_SUBMITTED = 'auto_submitted';
    public const REASON_NULL_RETURN_PATH = 'bounce_null_sender';
    public const REASON_DELIVERY_REPORT = 'bounce_delivery_report';
    public const REASON_FAILED_RECIPIENTS = 'bounce_failed_recipients';
    public const REASON_MAILING_LIST = 'mailing_list';
    public const REASON_SUBJECT_HEURISTIC = 'auto_reply_subject';

    /** @param ParsedAttachment[] $attachments */
    public function __construct(
        public readonly ?string $messageId,
        public readonly ?string $inReplyTo,
        public readonly ?string $references,
        public readonly string $to,
        public readonly string $from,
        public readonly ?string $fromName,
        public readonly ?string $subject,
        public readonly ?string $bodyTextOriginal,
        public readonly ?string $bodyText,
        public readonly ?string $bodyHtml,
        public readonly array $attachments,
        /**
         * Null means a HUMAN wrote this. Anything else is one of the REASON_*
         * constants and the message must not be routed.
         */
        public readonly ?string $automatedReason = null,
    ) {
    }

    /**
     * Kept so every existing caller keeps working after the boolean became a
     * reason. `automatedReason` is the richer value; this is the question most
     * callers actually ask.
     */
    public function isAutoSubmitted(): bool
    {
        return $this->automatedReason !== null;
    }

    public function hasAttachments(): bool
    {
        return $this->attachments !== [];
    }

    /**
     * Is there anything a human actually wrote?
     *
     * An attachment with no words still counts: "here is my police certificate"
     * is frequently sent as a bare file with an empty body.
     */
    public function hasContent(): bool
    {
        return trim((string) $this->bodyText) !== ''
            || trim((string) $this->bodyHtml) !== ''
            || $this->hasAttachments();
    }
}
