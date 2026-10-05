<?php

namespace Tests\Fakes;

use App\Services\Messaging\MessagePublisher;
use App\Services\Messaging\MessagePublishException;
use PHPUnit\Framework\Assert;

/**
 * Pengganti publisher di test: mencatat pesan, atau gagal kalau disuruh.
 *
 *     $publisher = FakeMessagePublisher::swap();
 *     $publisher->failWith('Connection refused');
 */
class FakeMessagePublisher implements MessagePublisher
{
    /** @var array<int, array{exchange: string, routingKey: string, payload: array, messageId: string}> */
    public array $published = [];

    private ?string $failure = null;

    public static function swap(): self
    {
        $fake = new self();
        app()->instance(MessagePublisher::class, $fake);

        return $fake;
    }

    public function failWith(?string $error = 'Connection refused'): self
    {
        $this->failure = $error;

        return $this;
    }

    public function publish(string $exchange, string $routingKey, array $payload, string $messageId): void
    {
        if ($this->failure !== null) {
            throw new MessagePublishException($this->failure);
        }

        $this->published[] = compact('exchange', 'routingKey', 'payload', 'messageId');
    }

    public function assertPublishedCount(int $count): void
    {
        Assert::assertCount($count, $this->published, "Diharapkan {$count} pesan terkirim.");
    }

    public function assertNothingPublished(): void
    {
        $this->assertPublishedCount(0);
    }

    /** @return array{exchange: string, routingKey: string, payload: array, messageId: string} */
    public function last(): array
    {
        Assert::assertNotEmpty($this->published, 'Belum ada pesan yang terkirim.');

        return end($this->published);
    }
}
