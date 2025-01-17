<?php

namespace Tests\fixtures;

class MissingDateTest extends FixtureTestCase
{
    public function test_fixture(): void
    {
        $message = $this->getFixture('missing_date.eml');

        self::assertEquals('Nuu', $message->getSubject());
        self::assertEquals('Hi', $message->getTextBody());
        self::assertFalse($message->hasHTMLBody());
        self::assertFalse($message->date->first());
        self::assertEquals('from@here.com', $message->from->first()->mail);
        self::assertEquals('to@here.com', $message->to->first()->mail);
    }
}
