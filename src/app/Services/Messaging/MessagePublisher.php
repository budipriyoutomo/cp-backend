<?php

namespace App\Services\Messaging;

/**
 * Kirim satu pesan keluar ke broker.
 *
 * Kontraknya: kembali tanpa exception HANYA kalau broker sudah mengonfirmasi
 * pesan itu dan merutekannya ke minimal satu queue. Selain itu lempar
 * MessagePublishException — pemanggil (outbox) mengandalkan ini untuk
 * memutuskan apakah pesan perlu dikirim ulang.
 */
interface MessagePublisher
{
    /**
     * @throws MessagePublishException
     */
    public function publish(string $exchange, string $routingKey, array $payload, string $messageId): void;
}
