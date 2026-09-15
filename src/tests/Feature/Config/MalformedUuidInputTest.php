<?php

namespace Tests\Feature\Config;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\Concerns\SeedsProductionData;
use Tests\TestCase;

/**
 * `uuid` adalah tipe sungguhan di PostgreSQL. Membandingkannya dengan string
 * sembarang bukan menghasilkan "tidak ketemu" melainkan
 *
 *   SQLSTATE[22P02]: invalid input syntax for type uuid: "abc"
 *
 * yaitu 500 dengan SQL bocor ke klien. Nilainya datang dari `?outlet_id=`, dari
 * body, dan dari segmen URL — jadi apa pun bisa masuk. Rule `exists:` ikut
 * meledak karena ia sendiri menjalankan query: `['required', 'exists:outlets,id']`
 * mengirim "abc" ke kolom uuid, sementara `['required', 'uuid', 'exists:...']`
 * berhenti di rule `uuid`.
 *
 * Suite ini jalan di SQLite yang bertipe longgar, jadi sebagian aturannya tidak
 * bisa dibuktikan lewat respons — di sana "abc" masuk tanpa keluhan. Karena itu
 * ada dua macam test di sini: yang memeriksa respons untuk endpoint yang memang
 * berubah perilakunya, dan yang membaca kode sumber untuk aturan yang hanya
 * terlihat di PostgreSQL.
 */
class MalformedUuidInputTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;
    use SeedsProductionData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsRole('admin');
    }

    /**
     * Endpoint yang meneruskan outlet id mentah ke `where('outlet_id', ...)`.
     * Sebelum diperbaiki semuanya lolos di SQLite dan 500 di PostgreSQL.
     *
     * @return array<string, array{0: string}>
     */
    public static function outletFilteredEndpoints(): array
    {
        return [
            'get data POS'        => ['/api/reports/pos-data?outletId=abc&date=2026-06-17'],
            'sales by date'       => ['/api/sales/by-date?outlet_id=abc&date=2026-06-17'],
            'daftar sales'        => ['/api/sales?outlet_id=abc'],
            'daftar closing'      => ['/api/closing-reports?outletId=abc'],
            'waste index'         => ['/api/waste?outletId=abc&date=2026-06-17'],
            'waste summary'       => ['/api/waste/summary?outletId=abc&date=2026-06-17'],
            'data closing report' => ['/api/closing-reports/data?outletId=abc&date=2026-06-17'],
        ];
    }

    /**
     * @dataProvider outletFilteredEndpoints
     */
    public function test_malformed_outlet_id_is_rejected_with_422(string $url): void
    {
        $this->getJson($url)->assertStatus(422);
    }

    public function test_get_pos_data_rejects_a_missing_outlet_id(): void
    {
        // Endpoint ini dulu tidak memvalidasi apa pun: outletId yang hilang pun
        // langsung masuk ke query.
        $this->getJson('/api/reports/pos-data?date=2026-06-17')->assertStatus(422);
    }

    public function test_submitting_sales_with_a_malformed_outlet_id_is_rejected(): void
    {
        $this->postJson('/api/sales', [
            'outlet_id' => 'abc',
            'date'      => '2026-06-17',
            'status'    => 'draft',
            'items'     => [],
        ])->assertStatus(422);
    }

    /**
     * Segmen URL yang bentuknya salah tidak boleh sampai ke `find()`. Dengan
     * batasan route, ia tidak pernah cocok dengan route-nya sama sekali.
     *
     * @return array<string, array{0: string}>
     */
    public static function idRoutes(): array
    {
        return [
            'sales show'          => ['/api/sales/abc'],
            'closing show'        => ['/api/closing-reports/abc'],
            'waste show'          => ['/api/waste/abc'],
            'master menu'         => ['/api/master/menu/abc'],
            'master platecolor'   => ['/api/master/platecolor/abc'],
            'master outlet'       => ['/api/master/outlet/abc'],
            'master brand'        => ['/api/master/brand/abc'],
            'master waste reason' => ['/api/master/waste-reason/abc'],
            // users.id auto-increment, jadi batasannya angka — bukan uuid.
            'user bukan angka'    => ['/api/users/abc'],
        ];
    }

    /**
     * @dataProvider idRoutes
     */
    public function test_malformed_id_segment_never_reaches_the_query(string $url): void
    {
        $this->getJson($url)->assertStatus(404);
    }

    /**
     * Aturan yang tidak bisa dibuktikan dari SQLite: setiap `exists:` pada tabel
     * ber-primary-key uuid harus didahului `uuid` di rule set yang sama. Tanpa
     * itu, rule `exists` sendiri yang menjalankan query ke kolom uuid.
     */
    public function test_every_exists_rule_is_guarded_by_a_uuid_rule(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(app_path('Http')) as $path) {
            foreach (file($path) as $number => $line) {
                if (! preg_match('/exists:(outlets|plate_colors|menus|brands|production_items),id/', $line)) {
                    continue;
                }

                if (! str_contains($line, "'uuid'") && ! str_contains($line, '|uuid|')) {
                    $offenders[] = basename($path) . ':' . ($number + 1);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Rule `exists:` pada tabel ber-primary-key uuid harus didahului rule `uuid`, '
                . 'kalau tidak validatornya sendiri yang menjawab 500. Pelanggar: '
                . implode(', ', $offenders)
        );
    }

    /**
     * Aturan kedua yang tidak terlihat dari SQLite: setiap route dengan segmen
     * id harus dibatasi bentuknya.
     */
    public function test_every_id_route_constrains_its_segment(): void
    {
        $offenders = [];

        $sources = [
            base_path('routes/api.php'),
            app_path('Providers/RouteServiceProvider.php'),
        ];

        foreach ($sources as $path) {
            foreach (file($path) as $number => $line) {
                if (! preg_match('/Route::(get|put|patch|delete)\([^)]*\{(id|waste)\}/', $line)) {
                    continue;
                }

                if (! str_contains($line, 'whereUuid') && ! str_contains($line, 'whereNumber')) {
                    $offenders[] = basename($path) . ':' . ($number + 1);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Route dengan segmen id harus dibatasi `whereUuid()` (atau `whereNumber()` '
                . 'untuk users). Pelanggar: ' . implode(', ', $offenders)
        );
    }

    /**
     * @return array<int, string>
     */
    private function phpFilesIn(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
