<?php

namespace App\Http\Requests\ClosingReport;

use App\Http\Requests\BaseRequest;

/**
 * Foto waste disimpan public di S3, jadi apa pun yang lolos di sini bisa
 * dibuka siapa saja dari domain bucket.
 *
 * `mimes`, bukan `image`: di Laravel 10 rule `image` menerima SVG — dokumen
 * XML yang boleh berisi `<script>` (stored XSS). `mimes` mencocokkan ekstensi
 * hasil deteksi **isi** file oleh server, bukan nama dari klien.
 *
 * Daftarnya sengaja sempit: kamera tablet menghasilkan JPEG; PNG/WebP untuk
 * tangkapan layar. GIF/HEIC/BMP tidak dibutuhkan laporan mana pun. Input file
 * di frontend (`components/closing-report.tsx`) memakai daftar yang sama.
 */
class UploadWastePhotosRequest extends BaseRequest
{
    protected function rulesForCreate(): array
    {
        return [
            'photos'   => ['required', 'array', 'min:1', 'max:10'],
            'photos.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
