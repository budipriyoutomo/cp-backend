<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * PINs were stored and compared in plaintext.
 *
 * After this migration `users.pin` holds a bcrypt hash and `users.pin_lookup`
 * holds a keyed HMAC of the same PIN — the blind index that keeps login a
 * single indexed query.
 *
 * IRREVERSIBLE IN PRACTICE: down() drops the lookup column but cannot restore
 * the plaintext PINs, so rolling back means re-issuing PINs to kitchen staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertNoDuplicatePins();

        Schema::table('users', function (Blueprint $table) {
            $table->string('pin_lookup')->nullable()->unique()->after('pin');
        });

        DB::table('users')
            ->whereNotNull('pin')
            ->orderBy('id')
            ->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    $pin = (string) $user->pin;

                    // Idempotent: skip anything already bcrypt-hashed.
                    if ($pin === '' || str_starts_with($pin, '$2y$') || str_starts_with($pin, '$2a$')) {
                        continue;
                    }

                    DB::table('users')->where('id', $user->id)->update([
                        'pin'        => Hash::make($pin),
                        'pin_lookup' => hash_hmac('sha256', trim($pin), (string) config('app.key')),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['pin_lookup']);
            $table->dropColumn('pin_lookup');
        });
    }

    /**
     * users.pin never had a unique constraint — only API-level validation — so
     * duplicates can exist in an older database. They would blow up halfway
     * through, once some PINs are already hashed and unrecoverable. Fail before
     * writing anything, and name the users that need a new PIN.
     */
    private function assertNoDuplicatePins(): void
    {
        $duplicates = DB::table('users')
            ->select('pin', DB::raw('COUNT(*) as total'))
            ->whereNotNull('pin')
            ->where('pin', '!=', '')
            ->where('pin', 'not like', '$2y$%')
            ->where('pin', 'not like', '$2a$%')
            ->groupBy('pin')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('pin');

        if ($duplicates->isEmpty()) {
            return;
        }

        $names = DB::table('users')
            ->whereIn('pin', $duplicates)
            ->orderBy('pin')
            ->get(['id', 'name'])
            ->map(fn ($u) => "#{$u->id} {$u->name}")
            ->implode(', ');

        throw new RuntimeException(
            'Migrasi dibatalkan: ada PIN kembar di tabel users, sementara pin_lookup harus unik. '
            . 'Beri PIN baru ke salah satu dari user berikut lalu jalankan ulang: ' . $names
        );
    }
};
