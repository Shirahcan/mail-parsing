<?php

namespace Shirahcan\MailParsing\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shirahcan\MailParsing\InboundEmailParser;
use Shirahcan\MailParsing\ParsedInboundEmail;

class InboundEmailParserTest extends TestCase
{
    private InboundEmailParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new InboundEmailParser();
    }

    private function raw(string $headers, string $body): string
    {
        return str_replace("\n", "\r\n", trim($headers) . "\n\n" . $body);
    }

    #[Test]
    public function it_extracts_the_headers_and_body(): void
    {
        $parsed = $this->parser->parse($this->raw(
            <<<'H'
            From: Maria Garcia <maria@example.com>
            To: rabcdefghijklmnopqrstuvwxyz234567@reply.portify.shirah.co
            Subject: Re: Your document checklist
            Message-ID: <abc123@mail.example.com>
            In-Reply-To: <orig456@portify.shirah.co>
            Content-Type: text/plain; charset=utf-8
            H,
            "I have uploaded my police certificate."
        ));

        $this->assertSame('maria@example.com', $parsed->from);
        $this->assertSame('Maria Garcia', $parsed->fromName);
        $this->assertSame('Re: Your document checklist', $parsed->subject);
        // ⚠ Stored WITHOUT angle brackets. Dedup compares our stored id against
        // an incoming one and threading compares In-Reply-To against ids we sent;
        // senders differ on whether the brackets survive, so storing them raw
        // makes the same message compare unequal to itself and ingest twice.
        $this->assertSame('abc123@mail.example.com', $parsed->messageId);
        $this->assertSame('orig456@portify.shirah.co', $parsed->inReplyTo);
        $this->assertStringContainsString('police certificate', (string) $parsed->bodyText);
        $this->assertFalse($parsed->isAutoSubmitted());
        $this->assertTrue($parsed->hasContent());
    }

    #[Test]
    public function it_lowercases_the_sender_address(): void
    {
        // The domain is case-insensitive and senders do shout. Storing it raw
        // would make sender-matching in the router miss and send a legitimate
        // reply to triage.
        $parsed = $this->parser->parse($this->raw(
            "From: MARIA@EXAMPLE.COM\nTo: x@y.co\nSubject: hi",
            'body'
        ));

        $this->assertSame('maria@example.com', $parsed->from);
    }

    #[Test]
    public function it_strips_the_quoted_reply_history(): void
    {
        $parsed = $this->parser->parse($this->raw(
            "From: a@b.co\nTo: x@y.co\nSubject: Re: hi",
            "Yes, that works for me.\n\nOn Mon, 1 Sep 2026 at 09:00, Portify wrote:\n> Are you free Tuesday?\n> Let us know.\n"
        ));

        $this->assertStringContainsString('Yes, that works for me.', (string) $parsed->bodyText);
        $this->assertStringNotContainsString('Are you free Tuesday', (string) $parsed->bodyText);

        // ⚠ The unstripped version is retained. Over-eager stripping silently
        // eats real content, and without this there is no way to recover it.
        $this->assertStringContainsString('Are you free Tuesday', (string) $parsed->bodyTextOriginal);
    }

    #[Test]
    public function stripping_never_returns_an_empty_body(): void
    {
        // A message that is ENTIRELY quoted text would otherwise strip to nothing
        // and look like the person sent a blank reply.
        $result = $this->parser->stripQuotedReply("On Mon, Portify wrote:\n> everything\n");

        $this->assertNotSame('', trim($result));
    }

    #[Test]
    public function it_flags_automated_mail_so_it_is_never_bounced(): void
    {
        foreach ([
            'Auto-Submitted: auto-replied' => ParsedInboundEmail::REASON_AUTO_SUBMITTED,
            'X-Autoreply: yes' => ParsedInboundEmail::REASON_AUTO_SUBMITTED,
            'Precedence: bulk' => ParsedInboundEmail::REASON_MAILING_LIST,
            'List-Id: <news.example.com>' => ParsedInboundEmail::REASON_MAILING_LIST,
            'List-Unsubscribe: <mailto:x@y.co>' => ParsedInboundEmail::REASON_MAILING_LIST,
            'X-Failed-Recipients: nobody@example.com' => ParsedInboundEmail::REASON_FAILED_RECIPIENTS,
        ] as $header => $expected) {
            $parsed = $this->parser->parse($this->raw(
                "From: a@b.co\nTo: x@y.co\nSubject: A normal subject\n{$header}",
                'I am away until Monday.'
            ));

            $this->assertTrue($parsed->isAutoSubmitted(), "not flagged: {$header}");
            $this->assertSame($expected, $parsed->automatedReason, "wrong reason: {$header}");
        }
    }

    /**
     * ⚠ `Return-Path: <>` is RFC 5321's DEFINITIVE bounce marker and was missing
     * entirely until 2026-09-03, so hard bounces reached the admin queue looking
     * like a person had written them.
     */
    #[Test]
    public function a_null_return_path_is_recognised_as_a_bounce(): void
    {
        $parsed = $this->parser->parse($this->raw(
            "From: MAILER-DAEMON@mx.example.com\nTo: x@y.co\nSubject: A normal subject\nReturn-Path: <>",
            'Your message could not be delivered.'
        ));

        $this->assertSame(ParsedInboundEmail::REASON_NULL_RETURN_PATH, $parsed->automatedReason);
    }

    #[Test]
    public function a_real_return_path_is_not_a_bounce(): void
    {
        $parsed = $this->parser->parse($this->raw(
            "From: a@b.co\nTo: x@y.co\nSubject: A normal subject\nReturn-Path: <a@b.co>",
            'A real person wrote this.'
        ));

        $this->assertNull($parsed->automatedReason);
    }

    /**
     * Exchange out-of-office replies have shipped WITHOUT `Auto-Submitted`, so
     * header-only detection misses the most common auto-reply in business email.
     */
    #[Test]
    public function it_recognises_an_automated_subject_when_no_header_says_so(): void
    {
        foreach ([
            'Out of Office: Kemi Adebayo',
            'Automatic reply: your message',
            'Undeliverable: Your case update',
            'Delivery Status Notification (Failure)',
            'Re: Out of office',
        ] as $subject) {
            $parsed = $this->parser->parse($this->raw(
                "From: a@b.co\nTo: x@y.co\nSubject: {$subject}",
                'I am away until Monday.'
            ));

            $this->assertSame(
                ParsedInboundEmail::REASON_SUBJECT_HEURISTIC,
                $parsed->automatedReason,
                "not flagged: {$subject}"
            );
        }
    }

    /**
     * ⚠ THE NEGATIVE CASE THAT MATTERS MOST. A false positive here withholds a
     * real person's reply from their consultant, which is far worse than a
     * bounce reaching a triage queue. The heuristics are anchored at the START
     * of the subject precisely so these two survive.
     */
    #[Test]
    public function a_human_reply_that_merely_mentions_auto_replies_is_not_eaten(): void
    {
        $parsed = $this->parser->parse($this->raw(
            "From: a@b.co\nTo: x@y.co\nSubject: Re: our automatic reply setup",
            'Can you turn the out of office message off for me?'
        ));

        $this->assertNull($parsed->automatedReason, 'a real reply was misjudged as automated');

        // A body quoting the words must not trip it either - only headers and
        // the subject are consulted.
        $second = $this->parser->parse($this->raw(
            "From: a@b.co\nTo: x@y.co\nSubject: Re: your question",
            "I got your note while I was out of office, here is my answer."
        ));

        $this->assertNull($second->automatedReason);
    }

    #[Test]
    public function auto_submitted_no_is_not_treated_as_automated(): void
    {
        // RFC 3834 defines "no" as the explicit value for human-authored mail.
        // Treating it as automated would silently discard real replies.
        $parsed = $this->parser->parse($this->raw(
            "From: a@b.co\nTo: x@y.co\nSubject: hi\nAuto-Submitted: no",
            'A real person wrote this.'
        ));

        $this->assertFalse($parsed->isAutoSubmitted());
        $this->assertNull($parsed->automatedReason);
    }

    #[Test]
    public function it_extracts_attachments_and_neutralises_path_traversal_in_filenames(): void
    {
        $raw = str_replace("\n", "\r\n", <<<'EOT'
        From: a@b.co
        To: x@y.co
        Subject: Documents
        Content-Type: multipart/mixed; boundary="BOUND"

        --BOUND
        Content-Type: text/plain

        See attached.
        --BOUND
        Content-Type: application/pdf
        Content-Disposition: attachment; filename="../../../etc/passwd.pdf"
        Content-Transfer-Encoding: base64

        SGVsbG8gd29ybGQ=
        --BOUND--
        EOT);

        $parsed = $this->parser->parse($raw);

        $this->assertTrue($parsed->hasAttachments());
        $this->assertCount(1, $parsed->attachments);

        // ⚠ The filename comes from the open internet and is used to build a
        // storage path. Any directory component must be gone.
        $name = $parsed->attachments[0]->filename;
        $this->assertSame('passwd.pdf', $name);
        $this->assertStringNotContainsString('..', $name);
        $this->assertStringNotContainsString('/', $name);
    }

    #[Test]
    public function an_attachment_only_message_still_counts_as_content(): void
    {
        // "Here is my police certificate" is frequently sent as a bare file with
        // an empty body. Treating that as empty would drop it.
        $raw = str_replace("\n", "\r\n", <<<'EOT'
        From: a@b.co
        To: x@y.co
        Subject: (no subject)
        Content-Type: multipart/mixed; boundary="B"

        --B
        Content-Type: application/pdf
        Content-Disposition: attachment; filename="cert.pdf"
        Content-Transfer-Encoding: base64

        SGVsbG8=
        --B--
        EOT);

        $this->assertTrue($this->parser->parse($raw)->hasContent());
    }
}
