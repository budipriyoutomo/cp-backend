<?php

namespace Tests\Unit\Services\Messaging;

use App\Services\Messaging\MessagePublisher;
use App\Services\Messaging\MessagePublishException;
use App\Services\Messaging\RabbitConnectionFactory;
use App\Services\Messaging\RabbitMessagePublisher;
use Mockery;
use Mockery\MockInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;
use Tests\TestCase;

/**
 * Publisher for outbound messages (first user: `closingreport.submitted` to BI).
 *
 * The broker is mocked at the channel level — the AMQP wire protocol is
 * php-amqplib's job. What is tested is our contract: a publish only counts as
 * done when the broker has confirmed it AND routed it to at least one queue.
 * Anything less must throw, so the outbox row stays due for a retry instead of
 * being marked published while the message is gone.
 */
class RabbitMessagePublisherTest extends TestCase
{
    private const EXCHANGE = 'closingreport_exchange';
    private const ROUTING_KEY = 'closingreport.submitted';

    /** @var AMQPChannel&MockInterface */
    private $channel;

    /** @var AbstractConnection&MockInterface */
    private $connection;

    private ?AMQPMessage $published = null;
    private array $publishArgs = [];

    /** @var array<string, callable> */
    private array $handlers = [];

    /**
     * What the broker "does" while we wait for confirms: 'ack', 'nack',
     * 'return' (unroutable, then ack — as RabbitMQ does), 'silent' or 'timeout'.
     */
    private string $brokerReply = 'ack';

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Mockery::mock(AMQPChannel::class);
        $this->connection = Mockery::mock(AbstractConnection::class);

        $this->connection->shouldReceive('channel')->andReturn($this->channel)->byDefault();
        $this->connection->shouldReceive('close')->byDefault();

        $this->channel->shouldReceive('confirm_select')->byDefault();
        $this->channel->shouldReceive('exchange_declare')->byDefault();
        $this->channel->shouldReceive('close')->byDefault();

        foreach (['set_ack_handler' => 'ack', 'set_nack_handler' => 'nack', 'set_return_listener' => 'return'] as $method => $key) {
            $this->channel->shouldReceive($method)
                ->andReturnUsing(function (callable $cb) use ($key) {
                    $this->handlers[$key] = $cb;
                })
                ->byDefault();
        }

        $this->channel->shouldReceive('basic_publish')
            ->andReturnUsing(function (AMQPMessage $msg, ...$args) {
                $this->published = $msg;
                $this->publishArgs = $args;
            })
            ->byDefault();

        $this->channel->shouldReceive('wait_for_pending_acks_returns')
            ->andReturnUsing(function () {
                match ($this->brokerReply) {
                    'ack'     => ($this->handlers['ack'])($this->published),
                    'nack'    => ($this->handlers['nack'])($this->published),
                    'return'  => $this->returnThenAck(),
                    'timeout' => throw new AMQPTimeoutException('The connection timed out after 5 sec while awaiting incoming data'),
                    'silent'  => null,
                };
            })
            ->byDefault();
    }

    private function returnThenAck(): void
    {
        ($this->handlers['return'])(312, 'NO_ROUTE', self::EXCHANGE, self::ROUTING_KEY, $this->published);
        ($this->handlers['ack'])($this->published);
    }

    private function publisher(?RabbitConnectionFactory $factory = null): RabbitMessagePublisher
    {
        if ($factory === null) {
            $factory = Mockery::mock(RabbitConnectionFactory::class);
            $factory->shouldReceive('make')->andReturn($this->connection);
        }

        return new RabbitMessagePublisher($factory, confirmTimeout: 5);
    }

    private function publish(array $payload = ['event' => 'closingreport.submitted'], string $messageId = 'report-1'): void
    {
        $this->publisher()->publish(self::EXCHANGE, self::ROUTING_KEY, $payload, $messageId);
    }

    public function test_publishes_to_a_durable_direct_exchange_with_confirms_enabled(): void
    {
        $this->channel->shouldReceive('confirm_select')->once();
        $this->channel->shouldReceive('exchange_declare')
            ->once()
            ->with(self::EXCHANGE, 'direct', false, true, false);

        $this->publish();

        $this->assertSame([self::EXCHANGE, self::ROUTING_KEY, true], $this->publishArgs);
    }

    public function test_message_is_persistent_json_carrying_the_message_id(): void
    {
        $payload = [
            'event' => 'closingreport.submitted',
            'data'  => ['outlet' => ['name' => 'Maharasa Plaza Senayan'], 'items' => [['menuCode' => null]]],
        ];

        $this->publish($payload, 'b0c6a1d2-0000-4000-8000-000000000001');

        $this->assertSame($payload, json_decode($this->published->getBody(), true));
        $this->assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $this->published->get('delivery_mode'));
        $this->assertSame('application/json', $this->published->get('content_type'));
        $this->assertSame('b0c6a1d2-0000-4000-8000-000000000001', $this->published->get('message_id'));
    }

    public function test_a_nack_from_the_broker_fails_the_publish(): void
    {
        $this->brokerReply = 'nack';

        $this->expectException(MessagePublishException::class);

        $this->publish();
    }

    public function test_an_unroutable_message_fails_the_publish(): void
    {
        // No queue bound yet on the BI side: RabbitMQ returns the message and
        // still acks it. Counting that ack as success would lose the message.
        $this->brokerReply = 'return';

        $this->expectException(MessagePublishException::class);
        $this->expectExceptionMessage('NO_ROUTE');

        $this->publish();
    }

    public function test_no_confirm_at_all_fails_the_publish(): void
    {
        $this->brokerReply = 'silent';

        $this->expectException(MessagePublishException::class);

        $this->publish();
    }

    public function test_a_confirm_timeout_fails_the_publish(): void
    {
        $this->brokerReply = 'timeout';

        $this->expectException(MessagePublishException::class);

        $this->publish();
    }

    public function test_an_unreachable_broker_fails_the_publish(): void
    {
        $factory = Mockery::mock(RabbitConnectionFactory::class);
        $factory->shouldReceive('make')->andThrow(new RuntimeException('Connection refused'));

        $this->expectException(MessagePublishException::class);
        $this->expectExceptionMessage('Connection refused');

        $this->publisher($factory)->publish(self::EXCHANGE, self::ROUTING_KEY, [], 'report-1');
    }

    public function test_channel_and_connection_are_closed_after_success(): void
    {
        $this->channel->shouldReceive('close')->once();
        $this->connection->shouldReceive('close')->once();

        $this->publish();
    }

    public function test_channel_and_connection_are_closed_after_failure(): void
    {
        $this->brokerReply = 'nack';
        $this->channel->shouldReceive('close')->once();
        $this->connection->shouldReceive('close')->once();

        try {
            $this->publish();
            $this->fail('Expected MessagePublishException');
        } catch (MessagePublishException) {
            // expected
        }
    }

    public function test_a_failing_close_does_not_mask_a_successful_publish(): void
    {
        $this->connection->shouldReceive('close')->andThrow(new RuntimeException('Broken pipe'));

        $this->publish();

        $this->assertNotNull($this->published);
    }

    public function test_the_container_resolves_the_rabbit_publisher(): void
    {
        $this->assertInstanceOf(RabbitMessagePublisher::class, app(MessagePublisher::class));
    }

    public function test_closing_report_destination_defaults_match_the_agreed_contract(): void
    {
        $this->assertSame('closingreport_exchange', config('rabbitmq.closing_report.exchange'));
        $this->assertSame('closingreport.submitted', config('rabbitmq.closing_report.routing_key'));
    }
}
