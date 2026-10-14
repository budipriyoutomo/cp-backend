<?php

namespace App\Services\Production;

use App\Models\WasteReason;
use Illuminate\Support\Collection;

/**
 * Snapshot reason + kategori untuk satu baris `waste_records`.
 *
 * Layar dapur mengirim reason sebagai TEKS (`reason_name`), bukan id, dan itu
 * sengaja tidak diubah. Kelas ini mencocokkan teks itu ke master saat waste
 * dicatat, lalu membekukan reason dan kategorinya di baris waste. Laporan dan
 * payload BI membaca snapshot itu, jadi memindahkan reason ke kategori lain
 * tidak mengubah hari yang sudah lewat.
 *
 * Tidak pernah melempar: teks yang tidak dikenal menghasilkan snapshot kosong
 * (NULL/NULL), dan baris waste tetap tercatat. Mencatat waste lebih penting
 * daripada mengategorikannya — yang kosong muncul sebagai "Belum
 * dikategorikan" dan bisa diisi `waste:backfill-category`.
 */
class WasteReasonResolver
{
    private const EMPTY = ['waste_reason_id' => null, 'waste_category_id' => null];

    /** @var Collection<string, WasteReason>|null nama (lowercase) => reason */
    private ?Collection $byName = null;

    /**
     * @return array{waste_reason_id: ?string, waste_category_id: ?string}
     */
    public function forText(?string $text): array
    {
        $key = $this->normalize($text);

        if ($key === '') {
            return self::EMPTY;
        }

        return $this->snapshot($this->reasons()->get($key));
    }

    /**
     * @return array{waste_reason_id: ?string, waste_category_id: ?string}
     */
    public function forReason(?WasteReason $reason): array
    {
        return $this->snapshot($reason);
    }

    /**
     * Reason sistem (WasteReason::AUTO_WASTE, IMPORT_BACKDATE). Dicari lewat
     * nama karena itulah kunci yang dipakai migration untuk membuatnya.
     *
     * @return array{waste_reason_id: ?string, waste_category_id: ?string}
     */
    public function forSystem(string $name): array
    {
        return $this->forText($name);
    }

    private function snapshot(?WasteReason $reason): array
    {
        if (!$reason) {
            return self::EMPTY;
        }

        return [
            'waste_reason_id'   => $reason->id,
            'waste_category_id' => $reason->waste_category_id,
        ];
    }

    /**
     * Seluruh tabel dibaca sekali — isinya hitungan belasan baris. Reason yang
     * sudah dihapus ikut, supaya backfill bisa mengenali teks lama, tapi yang
     * masih hidup menang kalau namanya bentrok.
     */
    private function reasons(): Collection
    {
        return $this->byName ??= WasteReason::withTrashed()
            ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 1 ELSE 0 END')
            ->get()
            ->keyBy(fn (WasteReason $r) => $this->normalize($r->reason_name));
    }

    private function normalize(?string $text): string
    {
        return mb_strtolower(trim((string) $text));
    }
}
