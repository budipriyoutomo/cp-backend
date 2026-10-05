<?php

namespace App\Services\Messaging;

use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * Satu-satunya resep koneksi ke RabbitMQ, dipakai consumer POS maupun
 * publisher. Kalau parameter koneksi (heartbeat, timeout) berubah, cukup di sini.
 */
class RabbitConnectionFactory
{
    public function make(): AbstractConnection
    {
        return new AMQPStreamConnection(
            config('rabbitmq.host'),
            config('rabbitmq.port'),
            config('rabbitmq.user'),
            config('rabbitmq.password'),
            config('rabbitmq.vhost'),
            false,
            'AMQPLAIN',
            null,
            'en_US',
            10.0,   // connection timeout
            180.0,  // read/write timeout
            null,
            false,  // keepalive
            30      // heartbeat
        );
    }
}
