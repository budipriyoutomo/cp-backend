<?php

namespace App\Services\Production;

use App\Exceptions\BusinessRuleException;
use App\Models\WasteCategory;
use App\Models\WasteReason;
use App\Models\WasteRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mengisi snapshot kategori di `waste_records` yang tercatat sebelum fitur
 * kategori naik (`waste_category_id` NULL).
 *
 * Urutan pencocokan per baris:
 * 1. `waste_reason_id` sudah terisi → kategori reason itu saat ini.
 * 2. Teks dari close-stale (`Auto-waste: …`) → reason sistem Auto-waste.
 * 3. Teks bawaan impor backdate → reason sistem Import backdate.
 * 4. Teks sama dengan `reason_name` (abaikan besar-kecil & spasi tepi).
 * 5. Tidak cocok → kategori `--fallback` kalau diberikan, selain itu dibiarkan
 *    kosong (BI menerimanya sebagai UNCATEGORIZED).
 *
 * Berbeda dengan pencatatan baru, backfill memakai mapping SAAT INI — memang
 * tidak ada snapshot lain yang bisa dipakai untuk data lama. Karena itu jalankan
 * sebelum admin mulai memindahkan reason antar kategori.
 *
 * Baris yang sudah punya kategori tidak pernah disentuh, jadi aman diulang.
 */
class WasteCategoryBackfillService
{
    private const AUTO_WASTE_PREFIX = 'auto-waste';
    private const IMPORT_DEFAULT_TEXT = 'import produksi backdate';

    /**
     * @return array{
     *   total: int,
     *   matched: int,
     *   fallback: int,
     *   unmatched: int,
     *   byText: array<int, array{reason: string, count: int, category: ?string}>
     * }
     */
    public function run(bool $dryRun = true, ?string $fallbackCode = null): array
    {
        $fallback = $this->fallbackCategory($fallbackCode);
        $resolver = new WasteReasonResolver();
        $reasons  = WasteReason::withTrashed()->get()->keyBy('id');

        // Dikelompokkan per (teks, reason_id) — satu keputusan per kelompok,
        // bukan per baris. Tabelnya bisa besar, isinya hanya belasan teks.
        $groups = WasteRecord::query()
            ->whereNull('waste_category_id')
            ->groupBy('reason', 'waste_reason_id')
            ->get([
                'reason',
                'waste_reason_id',
                DB::raw('COUNT(*) as row_count'),
            ]);

        $summary = ['total' => 0, 'matched' => 0, 'fallback' => 0, 'unmatched' => 0, 'byText' => []];
        $codes   = WasteCategory::withTrashed()->pluck('code', 'id');

        foreach ($groups as $group) {
            $count    = (int) $group->row_count;
            $snapshot = $this->resolve($group->reason, $group->waste_reason_id, $resolver, $reasons);

            if (!$snapshot['waste_category_id'] && $fallback) {
                $snapshot['waste_category_id'] = $fallback->id;
                $summary['fallback'] += $count;
            } elseif ($snapshot['waste_category_id']) {
                $summary['matched'] += $count;
            } else {
                $summary['unmatched'] += $count;
            }

            $summary['total'] += $count;
            $summary['byText'][] = [
                'reason'   => (string) $group->reason,
                'count'    => $count,
                'category' => $codes[$snapshot['waste_category_id']] ?? null,
            ];

            if ($dryRun || !$snapshot['waste_category_id']) {
                continue;
            }

            WasteRecord::query()
                ->whereNull('waste_category_id')
                ->where('reason', $group->reason)
                ->when(
                    $group->waste_reason_id,
                    fn ($q) => $q->where('waste_reason_id', $group->waste_reason_id),
                    fn ($q) => $q->whereNull('waste_reason_id'),
                )
                ->update(array_filter($snapshot) + ['updated_at' => now()]);
        }

        usort($summary['byText'], fn ($a, $b) => $b['count'] <=> $a['count']);

        return $summary;
    }

    private function resolve(?string $text, ?string $reasonId, WasteReasonResolver $resolver, $reasons): array
    {
        if ($reasonId && ($reason = $reasons->get($reasonId))) {
            return $resolver->forReason($reason);
        }

        $normalized = mb_strtolower(trim((string) $text));

        if (Str::startsWith($normalized, self::AUTO_WASTE_PREFIX)) {
            return $resolver->forSystem(WasteReason::AUTO_WASTE);
        }

        if ($normalized === self::IMPORT_DEFAULT_TEXT) {
            return $resolver->forSystem(WasteReason::IMPORT_BACKDATE);
        }

        return $resolver->forText($text);
    }

    private function fallbackCategory(?string $code): ?WasteCategory
    {
        if ($code === null || $code === '') {
            return null;
        }

        $category = WasteCategory::where('code', strtoupper($code))->first();

        if (!$category) {
            throw new BusinessRuleException("Kategori dengan kode {$code} tidak ditemukan.");
        }

        return $category;
    }
}
