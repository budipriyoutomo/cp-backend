<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Tutup plate sisa hari sebelumnya tepat setelah pergantian hari.
        $schedule->command('production:close-stale')
            ->dailyAt('00:05')
            ->withoutOverlapping();

        // Buang record idempotensi lama. Retensi 7 hari jauh lebih lama dari
        // umur antrean offline, jadi tidak ada replay sah yang ikut terhapus.
        $schedule->command('idempotency:prune')
            ->dailyAt('03:15')
            ->withoutOverlapping();

        // Segarkan belt_status. Dulu ini efek samping GET /production/conveyor —
        // tiap tablet memicu dua UPDATE massal tiap 30 detik.
        $schedule->command('production:refresh-belt-status')
            ->everyMinute()
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
