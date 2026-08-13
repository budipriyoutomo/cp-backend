<?php

namespace Tests\Unit\Console;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * Guard against repeating the incident: `php artisan migrate:fresh` was run
 * while .env pointed at a remote database. It drops every table, and Laravel's
 * own production confirmation never fired because APP_ENV said "local" even
 * though DB_HOST was remote.
 */
class DangerousCommandGuardTest extends TestCase
{
    private function fire(string $command): void
    {
        Event::dispatch(new CommandStarting($command, new ArrayInput([]), new NullOutput()));
    }

    private function useConnection(string $driver, string $host): void
    {
        config([
            'database.default'                       => 'guard_test',
            'database.connections.guard_test.driver' => $driver,
            'database.connections.guard_test.host'   => $host,
            'database.connections.guard_test.database' => 'maharasa_db',
        ]);
    }

    protected function tearDown(): void
    {
        putenv('ALLOW_DESTRUCTIVE_DB_COMMANDS');
        unset($_ENV['ALLOW_DESTRUCTIVE_DB_COMMANDS']);
        parent::tearDown();
    }

    /**
     * @dataProvider destructiveCommands
     */
    public function test_it_blocks_destructive_commands_against_a_remote_host(string $command): void
    {
        $this->useConnection('pgsql', '202.138.226.247');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bukan localhost');

        $this->fire($command);
    }

    public static function destructiveCommands(): array
    {
        return [
            'migrate:fresh' => ['migrate:fresh'],
            'migrate:reset' => ['migrate:reset'],
            'db:wipe'       => ['db:wipe'],
        ];
    }

    public function test_it_allows_destructive_commands_against_localhost(): void
    {
        $this->useConnection('pgsql', '127.0.0.1');

        $this->fire('migrate:fresh');

        $this->assertTrue(true, 'A local database is the developer’s own to wipe.');
    }

    public function test_it_leaves_the_sqlite_test_database_alone(): void
    {
        $this->useConnection('sqlite', '');

        $this->fire('migrate:fresh');

        $this->assertTrue(true, 'RefreshDatabase relies on this working.');
    }

    public function test_it_does_not_touch_harmless_commands(): void
    {
        $this->useConnection('pgsql', '202.138.226.247');

        $this->fire('migrate');
        $this->fire('db:seed');
        $this->fire('production:close-stale');

        $this->assertTrue(true, 'Only the table-dropping commands are gated.');
    }

    public function test_the_override_lets_a_deliberate_remote_rebuild_through(): void
    {
        $this->useConnection('pgsql', '202.138.226.247');

        putenv('ALLOW_DESTRUCTIVE_DB_COMMANDS=true');
        $_ENV['ALLOW_DESTRUCTIVE_DB_COMMANDS'] = 'true';

        $this->fire('migrate:fresh');

        $this->assertTrue(true, 'Explicit opt-in is still possible, just not accidental.');
    }
}
