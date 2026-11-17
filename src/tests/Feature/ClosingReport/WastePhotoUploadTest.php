<?php

namespace Tests\Feature\ClosingReport;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Foto waste disimpan **public** di bucket S3. Dua hal yang dulu salah:
 *
 * 1. Rule `image` di Laravel 10 menerima SVG — dokumen XML yang boleh berisi
 *    `<script>`. Dibuka dari URL bucket, itu stored XSS di domain bucket.
 * 2. Ekstensi diambil dari `getClientOriginalExtension()`, yang dikendalikan
 *    klien: PNG bernama `foto.html` tersimpan sebagai `.html` dan disajikan
 *    sebagai halaman.
 *
 * Sekarang hanya jpg/png/webp yang diterima, dan ekstensi + Content-Type
 * diturunkan dari isi file hasil deteksi server.
 */
class WastePhotoUploadTest extends TestCase
{
    use RefreshDatabase;
    use CreatesUsers;

    /** PNG 1×1 yang sah — dibuat dari byte, supaya test tidak butuh ekstensi GD. */
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        $this->actingAsRole('operation');
    }

    /**
     * File sungguhan di disk dengan nama pilihan klien. Sengaja bukan
     * `UploadedFile::fake()`: file palsu menebak MIME dari **nama**, jadi
     * deteksi isi — yang justru sedang diuji — tidak pernah berjalan.
     */
    private function realFile(string $clientName, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $content);

        return new UploadedFile($path, $clientName, null, null, true);
    }

    private function realPng(string $clientName): UploadedFile
    {
        return $this->realFile($clientName, base64_decode(self::PNG_1X1));
    }

    private function upload(array $photos)
    {
        return $this->postJson('/api/closing-reports/upload-photos', ['photos' => $photos]);
    }

    public function test_svg_is_rejected(): void
    {
        $svg = $this->realFile(
            'waste.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>'
        );

        $this->upload([$svg])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photos.0'], 'errors');

        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_html_disguised_as_jpg_is_rejected(): void
    {
        // Ekstensi .jpg dari klien tidak berarti apa-apa; isinya HTML.
        $html = $this->realFile('waste.jpg', '<html><body><script>alert(1)</script></body></html>');

        $this->upload([$html])->assertStatus(422);

        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_extension_comes_from_detected_content_not_client_name(): void
    {
        $response = $this->upload([$this->realPng('foto.html')])->assertOk();

        $files = Storage::disk('s3')->allFiles('closing-report/waste-photos');

        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.png', $files[0]);
        $this->assertStringEndsWith('.png', $response->json('data.urls.0'));
        $this->assertStringNotContainsString('html', $files[0]);
    }

    public function test_uploads_are_throttled(): void
    {
        foreach (range(1, 10) as $i) {
            $this->upload([$this->realPng("foto{$i}.png")])->assertOk();
        }

        $this->upload([$this->realPng('foto11.png')])->assertStatus(429);
    }

    public function test_at_most_ten_photos_per_request(): void
    {
        $photos = array_map(fn ($i) => $this->realPng("foto{$i}.png"), range(1, 11));

        $this->upload($photos)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photos'], 'errors');
    }
}
