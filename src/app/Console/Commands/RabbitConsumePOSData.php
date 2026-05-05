<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Illuminate\Support\Facades\Log;
use App\Services\POSService;

class RabbitConsumePOSData extends Command
{
    protected $signature = 'rabbit:consume-posdata';
    protected $description = 'Consume POS data from RabbitMQ';

    public function handle(POSService $posService)
    {
        $this->info('🚀 Worker started, waiting for messages...');

        while (true) {
            try {

                // 🔥 CONNECT (with heartbeat + keepalive)
                $connection = new AMQPStreamConnection(
                    env('RABBITMQ_HOST', 'rabbitmq'),
                    env('RABBITMQ_PORT', 5672),
                    env('RABBITMQ_USER', 'maharasa'),
                    env('RABBITMQ_PASSWORD', 'maharasa123'),
                    env('RABBITMQ_VHOST', '/'),
                    false,
                    'AMQPLAIN',
                    null,
                    'en_US',
                    3.0,
                    3.0,
                    null,
                    true,
                    60
                );

                $channel = $connection->channel();

                // ✅ Declare exchange & queue (idempotent)
                $channel->exchange_declare('posdata_exchange', 'direct', false, true, false);
                $channel->queue_declare('posdata.queue', false, true, false, false);
                $channel->queue_bind('posdata.queue', 'posdata_exchange', 'posdata.created');

                // ✅ Fair dispatch
                $channel->basic_qos(null, 1, null);

                $callback = function ($msg) use ($posService, $channel) {
                    try {
                        $payload = json_decode($msg->body, true);

                        if (!$payload || !isset($payload['data'])) {
                            throw new \Exception('Invalid payload');
                        }

                        Log::info('📥 POSData Received', $payload['data']);

                        $result = $posService->storeFromEvent($payload['data']);

                        Log::info('✅ POSData processed', $result);

                        $channel->basic_ack($msg->delivery_info['delivery_tag']);

                    } catch (\Throwable $e) {

                        Log::error('❌ ERROR CONSUME POSDATA: ' . $e->getMessage(), [
                            'body' => $msg->body
                        ]);

                        // ❗ jangan requeue (hindari infinite loop)
                        $channel->basic_nack(
                            $msg->delivery_info['delivery_tag'],
                            false,
                            false
                        );
                    }
                };

                $channel->basic_consume(
                    'posdata.queue',
                    '',
                    false,
                    false,
                    false,
                    false,
                    $callback
                );
 
                while (count($channel->callbacks)) {
                    $channel->wait(null, false, 30);
                }
            } catch (\Throwable $e) {

                Log::error('❌ RabbitMQ Connection Error: ' . $e->getMessage());

                // 🔥 cleanup (WAJIB)
                try {
                    if (isset($channel)) {
                        $channel->close();
                    }
                    if (isset($connection)) {
                        $connection->close();
                    }
                } catch (\Throwable $e) {}

                // 🔥 retry delay
                sleep(5);
            }
        }
    }
}