<?php

namespace App\Providers;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Refuses to run schema-destroying commands against a database that is not
 * clearly local.
 *
 * This exists because `php artisan migrate:fresh` was run while .env pointed at
 * a remote database. It drops every table, there is no undo, and Laravel's own
 * production confirmation does not trigger when APP_ENV says "local" — which it
 * did, even though the host was remote.
 *
 * Override for a genuine remote rebuild:
 *     ALLOW_DESTRUCTIVE_DB_COMMANDS=true
 */
class DangerousCommandGuardServiceProvider extends ServiceProvider
{
    /**
     * Commands that drop or truncate tables.
     */
    private const DESTRUCTIVE = [
        'migrate:fresh',
        'migrate:reset',
        'db:wipe',
    ];

    /**
     * Hosts that are unambiguously this machine.
     */
    private const LOCAL_HOSTS = [
        '127.0.0.1',
        'localhost',
        '::1',
        '',
    ];

    public function boot(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            if (!in_array($event->command, self::DESTRUCTIVE, true)) {
                return;
            }

            if (filter_var(env('ALLOW_DESTRUCTIVE_DB_COMMANDS', false), FILTER_VALIDATE_BOOLEAN)) {
                return;
            }

            $connection = config('database.default');
            $driver     = config("database.connections.{$connection}.driver");

            // The test suite runs on in-memory SQLite; nothing to protect.
            if ($driver === 'sqlite') {
                return;
            }

            $host     = (string) config("database.connections.{$connection}.host", '');
            $database = (string) config("database.connections.{$connection}.database", '');

            if (in_array($host, self::LOCAL_HOSTS, true)) {
                return;
            }

            throw new RuntimeException(
                "Perintah '{$event->command}' dibatalkan: DB_HOST={$host} bukan localhost "
                . "(database '{$database}'). Perintah ini menghapus SELURUH tabel dan tidak bisa dibatalkan.\n"
                . "Kalau ini memang disengaja, pastikan ada backup lalu jalankan ulang dengan "
                . 'ALLOW_DESTRUCTIVE_DB_COMMANDS=true.'
            );
        });
    }
}
