<?php

namespace Shirahcan\MailParsing;

/**
 * One file lifted out of an inbound message.
 *
 * ⚠ `content` is bytes from the open internet. It is written to storage and
 * handed to the documents-service for scanning, and it must never be served,
 * linked, or executed on the strength of `mimeType` alone: that value is
 * whatever the sender claimed, not what the bytes are.
 */
class ParsedAttachment
{
    public function __construct(
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly string $content,
    ) {
    }
}
