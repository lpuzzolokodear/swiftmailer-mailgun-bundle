<?php

namespace cspoo\Swiftmailer\MailgunBundle\Tests\Service;

use Mailgun\Connection\Exceptions\MissingEndpoint;
use Mailgun\Mailgun;
use cspoo\Swiftmailer\MailgunBundle\Service\MailgunTransport;

class MailgunTransportTest extends \PHPUnit_Framework_TestCase
{
    public function testGetPostData()
    {
        $class = 'cspoo\Swiftmailer\MailgunBundle\Service\MailgunTransport';
        $transport = $this->getMockBuilder($class)
        ->disableOriginalConstructor()
        ->setMethods(array('prepareRecipients'))
        ->getMock();

        $transport->expects($this->once())
            ->method('prepareRecipients')
            ->willReturn(array('foo' => 'bar'));

        $method = new \ReflectionMethod($class, 'getPostData');
        $method->setAccessible(true);

        $message = \Swift_Message::newInstance();
        $headers = $message->getHeaders();
        $headers->addTextHeader('o:deliverytime', 'tomorrow');

        $result = $method->invoke($transport, $message);

        // Is post data preserved?
        $this->assertTrue(isset($result['foo']));
        $this->assertEquals('bar', $result['foo']);

        $this->assertTrue(isset($result['o:deliverytime']));
        $this->assertEquals('tomorrow', $result['o:deliverytime']);

        // Is the header removed form the message
        $this->assertNull($headers->get('o:deliverytime'), 'Mailgun headers should be removed');
    }

    public function testGetDomain()
    {
        $class = 'cspoo\Swiftmailer\MailgunBundle\Service\MailgunTransport';
        $transport = $this->getTransport();

        $method = new \ReflectionMethod($class, 'getDomain');
        $method->setAccessible(true);

        // Test default domain
        $message = \Swift_Message::newInstance();
        $result = $method->invoke($transport, $message);
        $this->assertEquals('default.com', $result, 'Default domain should be returned when no domain header is used');

        // Test with domain header
        $message = \Swift_Message::newInstance();
        $headers = $message->getHeaders();
        $headers->addTextHeader('mg:domain', 'example.com');

        $result = $method->invoke($transport, $message);
        $this->assertEquals('example.com', $result);
    }

    public function testPrepareRecipients()
    {
        $class = 'cspoo\Swiftmailer\MailgunBundle\Service\MailgunTransport';
        $transport = $this->getTransport();

        $method = new \ReflectionMethod($class, 'prepareRecipients');
        $method->setAccessible(true);

        // Test with domain header
        $message = \Swift_Message::newInstance()
            ->setSubject('Foobar')
            ->setFrom('alice@example.com')
            ->setTo('bob@example.com')
            ->setCc('tobias@example.com')
            ->setBcc('eve@example.com')
            ->setBody('Message body');

        $result = $method->invoke($transport, $message);
        $this->assertTrue(isset($result['to']), 'PostData should always have a "to" field');
        $this->assertFalse(isset($result['cc']), 'PostData should never have a "cc" field');
        $this->assertFalse(isset($result['bcc']), 'PostData should never have a "bcc" field');

        // Make sure $result['to'] have all the recipients
        $this->assertTrue(in_array('bob@example.com', $result['to']));
        $this->assertTrue(in_array('tobias@example.com', $result['to']));
        $this->assertTrue(in_array('eve@example.com', $result['to']));
        $this->assertFalse(in_array('alice@example.com', $result['to']));

        // Make sure we got a from
        $this->assertTrue(in_array('alice@example.com', $result['from']));

        // Make sure we remove BCC from the message headers.
        $messageHeaders = $message->getHeaders();
        $this->assertNull($messageHeaders->get('bcc'));
    }

    public function testSendMessageOk()
    {
        $transport = $this->getTransport();

        $message = \Swift_Message::newInstance()
            ->setSubject('Foobar')
            ->setFrom('alice@example.com')
            ->setTo('bob@example.com')
            ->setCc('tobias@example.com')
            ->setBcc('eve@example.com')
            ->setBody('Message body');


        $failed = null;
        $sent = $transport->send($message, $failed);

        $this->assertEquals(3, $sent);
        $this->assertEmpty($failed);
    }

    public function testSendMessageWithException()
    {
        $dispatcher = $this->getMock('Swift_Events_EventDispatcher');
        $mailgun = $this->getMock('Mailgun\Mailgun');
        $logger = $this->getMock('Psr\Log\LoggerInterface');
        $transport = new MailgunTransport($dispatcher, $mailgun, 'default.com', $logger);

        $mailgun->expects($this->once())
            ->method('post')
            ->will($this->throwException(new MissingEndpoint('missing endpoint')));

        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->anything(),
                $this->callback(function ($context) {
                    return $context['exception_class'] === 'Mailgun\Connection\Exceptions\MissingEndpoint'
                        && $context['exception_message'] === 'missing endpoint';
                })
            );

        $message = \Swift_Message::newInstance()
             ->setSubject('Foobar')
             ->setFrom('alice@example.com')
             ->setTo('bob@example.com')
             ->setCc('tobias@example.com')
             ->setBcc('eve@example.com')
             ->setBody('Message body');

        $failed = null;
        $sent = $transport->send($message, $failed);

        $this->assertEquals(0, $sent);
        $this->assertEquals(['bob@example.com', 'eve@example.com', 'tobias@example.com'], $failed);
    }

    public function testSendPostsTheMimeMessageFromMemory()
    {
        $dispatcher = $this->getMock('Swift_Events_EventDispatcher');
        $mailgun = $this->getMock('Mailgun\Mailgun');
        $transport = new MailgunTransport($dispatcher, $mailgun, 'default.com');

        $result = new \stdClass();
        $result->http_response_code = 200;

        // The MIME body must travel as in-memory content, never as a file path.
        $mailgun->expects($this->never())
            ->method('sendMessage');

        $mailgun->expects($this->once())
            ->method('post')
            ->with(
                'default.com/messages.mime',
                $this->anything(),
                $this->callback(function ($files) {
                    return isset($files['message'][0]['fileContent'])
                        && false !== strpos($files['message'][0]['fileContent'], 'Message body')
                        && !isset($files['message'][0]['filePath']);
                })
            )
            ->willReturn($result);

        $failed = null;
        $sent = $transport->send($this->getMessage(), $failed);

        $this->assertEquals(3, $sent);
    }

    /**
     * A send that blows up must not leave a MIME temporary file behind. This is the
     * regression test for the /tmp/MG_TMP_MIME* leak that filled up the disk: the
     * deprecated Mailgun::sendMessage() only unlink()s the file after a successful
     * HTTP call, so every failed request used to leak one file forever.
     */
    public function testFailedSendDoesNotLeaveTemporaryMimeFiles()
    {
        $temporaryFilesBefore = $this->getMimeTempFiles();

        $httpClient = $this->getMock('Http\Client\HttpClient');
        $httpClient->expects($this->once())
            ->method('sendRequest')
            ->will($this->throwException(new \RuntimeException('connection timed out')));

        $dispatcher = $this->getMock('Swift_Events_EventDispatcher');
        $mailgun = new Mailgun('api-key', $httpClient, 'api.example.com');
        $transport = new MailgunTransport($dispatcher, $mailgun, 'default.com');

        $failed = null;
        $sent = $transport->send($this->getMessage(), $failed);

        $this->assertEquals(0, $sent);
        $this->assertEquals(
            array(),
            array_diff($this->getMimeTempFiles(), $temporaryFilesBefore),
            'A failed send must not leave MIME temporary files in the system temp directory'
        );
    }

    /**
     * @return MailgunTransport
     */
    private function getTransport()
    {
        $dispatcher = $this->getMock('Swift_Events_EventDispatcher');
        $dispatcher->expects($this->any())
            ->method('createSendEvent')
            ->willReturn($this->getMockBuilder('Swift_Events_SendEvent')->disableOriginalConstructor()->getMock());
            
        
        
        $mailgun = $this->getMock('Mailgun\Mailgun');
        $result = new \stdClass();
        $result->http_response_code = 200;

        $mailgun->expects($this->any())
            ->method('post')
            ->willReturn($result);

        return new MailgunTransport($dispatcher, $mailgun, 'default.com');
    }

    /**
     * @return \Swift_Message
     */
    private function getMessage()
    {
        return \Swift_Message::newInstance()
            ->setSubject('Foobar')
            ->setFrom('alice@example.com')
            ->setTo('bob@example.com')
            ->setCc('tobias@example.com')
            ->setBcc('eve@example.com')
            ->setBody('Message body');
    }

    /**
     * @return array
     */
    private function getMimeTempFiles()
    {
        return glob(sys_get_temp_dir().'/MG_TMP_MIME*');
    }
}
