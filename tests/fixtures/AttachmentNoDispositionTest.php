<?php

namespace Tests\fixtures;

use Webklex\PHPIMAP\Attachment;

class AttachmentNoDispositionTest extends FixtureTestCase
{
    public function test_fixture(): void
    {
        $message = $this->getFixture('attachment_no_disposition.eml');

        self::assertEquals('', $message->subject);
        self::assertEquals('multipart/mixed', $message->content_type->last());
        self::assertFalse($message->hasTextBody());
        self::assertFalse($message->hasHTMLBody());

        self::assertCount(1, $message->attachments());

        $attachment = $message->attachments()->first();

        self::assertInstanceOf(Attachment::class, $attachment);
        self::assertEquals('26ed3dd2', $attachment->filename);
        self::assertEquals('26ed3dd2', $attachment->id);
        self::assertEquals('Prostřeno_2014_poslední volné termíny.xls', $attachment->name);
        self::assertEquals('text', $attachment->type);
        self::assertEquals('xls', $attachment->getExtension());
        self::assertEquals('application/vnd.ms-excel', $attachment->content_type);
        self::assertEquals('a0ef7cfbc05b73dbcb298fe0bc224b41900cdaf60f9904e3fea5ba6c7670013c', hash('sha256', $attachment->content));
        self::assertEquals(146, $attachment->size);
        self::assertEquals(0, $attachment->part_number);
        self::assertNull($attachment->disposition);
        self::assertNotEmpty($attachment->id);
        self::assertEmpty($attachment->content_id);
    }
}
