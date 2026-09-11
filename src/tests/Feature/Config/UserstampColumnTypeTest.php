<?php

namespace Tests\Feature\Config;

use App\Models\SalesHeader;
use App\Models\SalesItem;
use App\Models\SalesItemDetail;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `users.id` adalah satu-satunya auto-increment di sistem ini, jadi
 * `Auth::id()` mengembalikan angka — bukan UUID. Kolom userstamp karenanya
 * harus longgar (`char(36)` lewat macro `fullstamps()`), bukan bertipe `uuid`.
 *
 * Tiga tabel sales pernah melanggar aturan itu dan menulis sendiri
 * `$table->uuid('created_by')`. Di PostgreSQL akibatnya fatal: setiap submit di
 * /operation/sales-input mati dengan
 * `invalid input syntax for type uuid: "1"`. `closing_reports.submitted_by`
 * melanggarnya juga, dan mematikan submit di /operation/closing-report dengan
 * pesan yang sama. Suite ini jalan di SQLite yang
 * bertipe longgar, jadi tidak ada test perilaku yang bisa menangkapnya —
 * satu-satunya cara menjaganya dari SQLite adalah membaca berkas migration-nya.
 */
class UserstampColumnTypeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    /**
     * Userstamp dari `HasUserstamps`, plus setiap kolom lain yang menampung id
     * user. Cara menemukan anggota baru: `grep -rn "auth()->id()\|Auth::id()" app/`
     * — setiap kolom tujuannya harus ada di daftar ini.
     */
    private const USER_ID_COLUMNS = ['created_by', 'updated_by', 'deleted_by', 'submitted_by'];

    public function test_no_migration_declares_a_user_id_column_as_uuid(): void
    {
        $offenders = [];

        foreach (glob(database_path('migrations/*.php')) as $path) {
            $source = $this->sourceWithoutComments($path);

            foreach (self::USER_ID_COLUMNS as $column) {
                if (preg_match("/->uuid\(\s*'{$column}'/", $source)) {
                    $offenders[] = basename($path) . " ({$column})";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Kolom userstamp tidak boleh bertipe uuid — Auth::id() berupa angka, dan PostgreSQL akan menolaknya. "
                . 'Pakai $table->fullstamps(). Pelanggar: ' . implode(', ', $offenders)
        );
    }

    /**
     * Komentar ikut terbaca kalau berkasnya diperiksa mentah — migration
     * perbaikannya mengutip bentuk yang salah di docblock, dan itu bukan
     * pelanggaran. Jadi yang dipindai hanya kode sungguhan.
     */
    private function sourceWithoutComments(string $path): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /**
     * Sisi lain dari aturan yang sama: yang benar-benar ditulis ke kolom itu
     * memang id user berbentuk angka, di ketiga level agregat sales.
     */
    public function test_sales_aggregate_stamps_the_numeric_user_id(): void
    {
        $user = $this->actingAsRole('admin');

        $outlet = $this->createOutlet();
        $color  = $this->createPlateColor();
        $menu   = $this->createMenu($color);

        $response = $this->postJson('/api/sales', [
            'outlet_id' => $outlet->id,
            'date'      => '2026-06-17',
            'status'    => 'submitted',
            'items'     => [
                [
                    'plate_color_id'   => $color->id,
                    'pos_sold'         => 10,
                    'production_sold'  => 5,
                    'production_waste' => 1,
                    'details'          => [
                        [
                            'menu_id'        => $menu->id,
                            'menu_name'      => $menu->menuname,
                            'total_produced' => 8,
                            'total_sold'     => 5,
                            'total_wasted'   => 1,
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertStatus(201);

        $expected = (string) $user->id;

        $this->assertSame($expected, trim((string) SalesHeader::sole()->created_by));
        $this->assertSame($expected, trim((string) SalesItem::sole()->created_by));
        $this->assertSame($expected, trim((string) SalesItemDetail::sole()->created_by));
    }
}
