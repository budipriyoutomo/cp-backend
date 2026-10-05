<?php

namespace App\Services\Messaging;

use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

/**
 * Publish ke RabbitMQ dengan publisher confirms.
 *
 * Satu koneksi per publish: pesan closing report hanya beberapa per outlet per
 * hari, jadi koneksi panjang tidak sebanding dengan urusan reconnect-nya.
 *
 * `mandatory` dipasang supaya pesan yang tidak punya queue tujuan dikembalikan
 * broker, bukan dibuang diam-diam. RabbitMQ tetap meng-ack pesan yang
 * dikembalikan, jadi ack saja tidak cukup sebagai bukti sampai.
 */
class RabbitMessagePublisher implements MessagePublisher
{
    public function __construct(
        private readonly RabbitConnectionFactory $connections,
        private readonly int $confirmTimeout = 5,
    ) {
    }

    public function publish(string $exchange, string $routingKey, array $payload, string $messageId): void
    {
        $connection = null;
        $channel = null;

        try {
            $connection = $this->connections->make();
            $channel = $connection->channel();

            $channel->confirm_select();
            $channel->exchange_declare($exchange, 'direct', false, true, false);

            $acked = false;
            $nacked = false;
            $returned = null;

            $channel->set_ack_handler(function () use (&$acked) {
                $acked = true;
            });
            $channel->set_nack_handler(function () use (&$nacked) {
                $nacked = true;
            });
            $channel->set_return_listener(function ($replyCode, $replyText) use (&$returned) {
                $returned = "{$replyCode} {$replyText}";
            });

            $message = new AMQPMessage(
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                [
                    'content_type'  => 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'message_id'    => $messageId,
                ]
            );

            $channel->basic_publish($message, $exchange, $routingKey, true);
            $channel->wait_for_pending_acks_returns($this->confirmTimeout);

            if ($returned !== null) {
                throw new MessagePublishException("Pesan tidak punya queue tujuan ({$returned}) di {$exchange}/{$routingKey}.");
            }
            if ($nacked) {
                throw new MessagePublishException("Broker menolak pesan (nack) di {$exchange}/{$routingKey}.");
            }
            if (! $acked) {
                throw new MessagePublishException("Tidak ada konfirmasi broker untuk pesan di {$exchange}/{$routingKey}.");
            }
        } catch (MessagePublishException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new MessagePublishException($e->getMessage(), 0, $e);
        } finally {
            $this->closeQuietly($channel, $connection);
        }
    }

    /**
     * Gagal menutup koneksi tidak boleh membatalkan publish yang sudah
     * dikonfirmasi — atau menutupi exception aslinya.
     */
    private function closeQuietly($channel, $connection): void
    {
        foreach ([$channel, $connection] as $resource) {
            try {
                $resource?->close();
            } catch (Throwable) {
                // sudah tidak ada yang bisa dilakukan
            }
        }
    }
}
