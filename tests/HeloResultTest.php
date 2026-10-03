<?php

namespace STS\HeloEmail\Tests;

use Illuminate\Mail\SentMessage as LaravelSentMessage;
use STS\HeloEmail\HeloException;
use STS\HeloEmail\HeloResult;
use STS\HeloEmail\Response;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class HeloResultTest extends TestCase
{
    public function testBuildsFromAnAcceptedResponse(): void
    {
        $result = HeloResult::fromResponse(new Response(['status' => 'delayed', 'messageId' => 'm-1', 'suppressions' => ['gone@example.com']]));

        $this->assertSame('m-1', $result->messageId);
        $this->assertTrue($result->isDelayed());
        $this->assertSame(['gone@example.com'], $result->suppressions);
        $this->assertSame(['messageId' => 'm-1', 'status' => 'delayed', 'suppressions' => ['gone@example.com']], $result->toArray());
    }

    public function testRejectsAResponseItCannotUse(): void
    {
        foreach ([[], ['status' => 'accepted'], ['status' => 'queued', 'messageId' => 'm-1'], ['status' => 'accepted', 'messageId' => ''], ['status' => 'accepted', 'messageId' => 'm-1', 'suppressions' => [1]]] as $json) {
            try {
                HeloResult::fromResponse(new Response($json));
                $this->fail('Expected a HeloException for '.json_encode($json));
            } catch (HeloException $exception) {
                $this->assertSame('Helo returned an invalid send result.', $exception->getMessage());
            }
        }
    }

    public function testReadsTheResultBackFromASentMessage(): void
    {
        $email = (new Email)->from('sender@example.com')->to('a@example.com')->text('Hi');
        (new HeloResult('m-1', 'accepted'))->attachTo($email);
        $sent = new SentMessage($email, new Envelope(new Address('sender@example.com'), [new Address('a@example.com')]));

        $this->assertSame('m-1', HeloResult::from($sent)?->messageId);
        $this->assertSame('m-1', HeloResult::from(new LaravelSentMessage($sent))?->messageId);
        $this->assertSame('m-1', HeloResult::from($email)?->messageId);
    }

    public function testReturnsNullForMailThatDidNotGoThroughHelo(): void
    {
        $email = (new Email)->from('sender@example.com')->to('a@example.com')->text('Hi');

        $this->assertNull(HeloResult::from($email));
    }

    public function testIgnoresAResultHeaderOnTheMessage(): void
    {
        // A header can be copied onto later sends; only the transport's own record counts.
        $email = (new Email)->from('sender@example.com')->to('a@example.com')->text('Hi');
        $email->getHeaders()->addTextHeader('X-Helo-Result', json_encode(['messageId' => 'm-1', 'status' => 'accepted', 'suppressions' => []]));

        $this->assertNull(HeloResult::from($email));
    }
}
