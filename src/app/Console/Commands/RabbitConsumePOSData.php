<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Illuminate\Support\Facades\Log;
use App\Models\FailedPosMessage;
use App\Services\POSService;

class RabbitConsumePOSData extends Command
{
    protected $signature = 'rabbit:consume-posdata';
    protected $description = 'Consume POS data from RabbitMQ';

    public function handle(POSService $posService)
    {
        $this->info('🚀 Worker started, waiting for messages...');
        $this->info('📡 Connecting...');
        $this->info('HOST: ' . config('rabbitmq.host'));
        $this->info('USER: ' . config('rabbitmq.user'));
        $this->info('VHOST: ' . config('rabbitmq.vhost'));

        while (true) {
            try {

                // 🔥 CONNECT (with heartbeat + keepalive)
                $connection = new AMQPStreamConnection(
                    config('rabbitmq.host'),
                    config('rabbitmq.port'),
                    config('rabbitmq.user'),
                    config('rabbitmq.password'),
                    config('rabbitmq.vhost'),
                    false,
                    'AMQPLAIN',
                    null,
                    'en_US',
                    10.0,
                    180.0,
                    null,
                    false,
                    30
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

                        // Park the message before dropping it. There is no
                        // dead-letter exchange, so without this the payload is
                        // gone for good and the day's POS data is unrecoverable.
                        try {
                            FailedPosMessage::create([
                                'payload' => $msg->body,
                                'error'   => $e->getMessage(),
                            ]);

                            // Safely stored, so the broker can let it go.
                            $channel->basic_ack($msg->delivery_info['delivery_tag']);
                        } catch (\Throwable $storeError) {
                            // Could not park it — leave it to the broker rather
                            // than losing it silently.
                            Log::error('❌ GAGAL MENYIMPAN DEAD LETTER POSDATA: ' . $storeError->getMessage());

                            $channel->basic_nack(
                                $msg->delivery_info['delivery_tag'],
                                false,
                                false
                            );
                        }
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
                $this->info('✅ SUBSCRIBED TO QUEUE');

                while (true) {
                    $channel->wait();
                }

            } catch (\Throwable $e) {

                Log::error('❌ RabbitMQ Connection Error: ' . $e->getMessage());
                $this->error('❌ ERROR: ' . $e->getMessage());
 
                try {
                    if (isset($channel)) {
                        $channel->close();
                    }
                    if (isset($connection)) {
                        $connection->close();
                    }
                } catch (\Throwable $e) {}
 
                sleep(5);
            }
        }
    }
}