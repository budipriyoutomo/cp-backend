<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Submit di /operation/sales-input gagal total di PostgreSQL:
 *
 *   SQLSTATE[22P02]: invalid input syntax for type uuid: "1"
 *
 * `users.id` adalah auto-increment (satu-satunya di sistem ini, lihat
 * ../CLAUDE.md), jadi `Auth::id()` mengembalikan angka. Semua tabel domain lain
 * menampung userstamp lewat macro `fullstamps()` di AppServiceProvider, yang
 * membuat kolomnya `char(36)` — cukup longgar untuk menampung "1".
 *
 * Tiga tabel sales tidak memakai macro itu; mereka menulis sendiri
 * `$table->uuid('created_by')`. Di PostgreSQL `uuid` adalah tipe sungguhan dan
 * menolak "1", jadi setiap insert header/item/detail sales mati. Migration ini
 * menyamakan ketiganya dengan tabel lain.
 *
 * Kenapa tidak ketahuan test: suite jalan di SQLite yang bertipe longgar —
 * `uuid` di sana hanya varchar dan "1" masuk tanpa keluhan. Jebakan yang sama
 * persis dengan `sales_headers.outlet_id` di docs/known-issues.md#8.
 *
 * Konversi ini tidak menghilangkan data: nilai lama pasti UUID sah (kolomnya
 * memang bertipe uuid) dan UUID muat di 36 karakter.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['sales_headers', 'sales_items', 'sales_item_details'];

    /** @var list<string> */
    private const COLUMNS = ['created_by', 'updated_by', 'deleted_by'];

    public function up(): void
    {
        // SQLite tidak punya tipe uuid, jadi tidak ada yang perlu diubah — dan
        // ALTER COLUMN TYPE memang tidak didukung di sana.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table) {
            foreach (self::COLUMNS as $column) {
                DB::statement(
                    "ALTER TABLE {$table} ALTER COLUMN {$column} TYPE char(36) USING {$column}::text"
                );
            }
        }
    }

    /**
     * Balik arah hanya aman kalau semua isinya masih UUID. Setelah aplikasi
     * jalan dengan kolom char, isinya adalah id user berupa angka — dan
     * `::uuid` akan gagal di tengah tabel tanpa menyebut baris mana. Jadi
     * diperiksa dulu, lalu ditolak dengan pesan yang bisa ditindaklanjuti.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table) {
            foreach (self::COLUMNS as $column) {
                $this->assertAllUuidShaped($table, $column);

                DB::statement(
                    "ALTER TABLE {$table} ALTER COLUMN {$column} TYPE uuid USING trim({$column})::uuid"
                );
            }
        }
    }

    private function assertAllUuidShaped(string $table, string $column): void
    {
        $bad = DB::table($table)
            ->whereNotNull($column)
            ->pluck($column, 'id')
            ->reject(fn ($value) => (bool) preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                trim((string) $value)
            ));

        if ($bad->isEmpty()) {
            return;
        }

        $detail = $bad->map(fn ($value, $id) => "{$id} ({$column}=" . trim((string) $value) . ')')->implode(', ');

        throw new RuntimeException(
            "Rollback dibatalkan: {$table}.{$column} berisi nilai yang bukan UUID, jadi tidak bisa dikembalikan ke tipe uuid. "
            . 'Baris berikut harus dibereskan dulu: ' . $detail
        );
    }
};
