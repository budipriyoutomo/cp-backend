<?php

namespace App\Services\Master;

use App\Models\Menu;
use App\Models\TimeSlot;
use App\Services\BaseService;
use App\Services\Concerns\ResolvesOutletBrand;
use Illuminate\Http\Request;

class TimeSlotService extends BaseService
{
    use ResolvesOutletBrand;
    use ScopesToOutletBrandStrictly;

    protected string $model = TimeSlot::class;
    protected array $relations = ['marker'];
    protected array $searchable = ['brand_id', 'is_active'];
    protected array $sortable = ['sort_order', 'start_time', 'created_at'];

    protected function buildQuery(Request $request)
    {
        return $this->scopeToOutletBrand(parent::buildQuery($request), $request)
            ->orderBy('start_time');
    }

    /**
     * Label kanonis semua slot aktif milik satu brand.
     *
     * Dipakai ProductionPlanRequest untuk memastikan plan hanya menyebut slot
     * yang memang dimiliki brand outletnya. Slot nonaktif tidak ikut: mematikan
     * slot berarti "jangan dipakai lagi mulai sekarang".
     *
     * @return list<string>
     */
    public function activeLabelsFor(?string $brandId): array
    {
        if ($brandId === null) {
            return [];
        }

        return TimeSlot::query()
            ->where('brand_id', $brandId)
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get(['start_time', 'end_time'])
            ->map(fn (TimeSlot $slot) => $slot->label)
            ->all();
    }

    /**
     * Ringkasan untuk layar setelan, dipanggil dengan outlet karena itulah yang
     * diketahui klien. Resolusi outlet → brand tetap satu pintu.
     *
     * @return array{repeatMinutes: int|null, longestShelfLife: int, ok: bool, message: string|null}
     */
    public function summaryForOutlet(string $outletId): array
    {
        return $this->markerCycleSummary($this->brandIdForOutlet($outletId));
    }

    /**
     * Apakah penanda berulang lebih cepat daripada umur piring.
     *
     * Inilah masalah yang melahirkan fitur ini. Dulu warnanya dihitung dari
     * sisa bagi nomor slot: 5 warna x 30 menit = 150 menit, sementara 206 dari
     * 285 menu hidup 180 menit. Antara menit ke-150 dan ke-180 ada dua batch
     * berwarna sama di belt — satu hampir mati, satu masih segar — dan staf
     * yang membaca penanda fisik tidak bisa membedakannya.
     *
     * Sekarang penanda dipilih per slot dan panjang slot bebas, jadi tidak ada
     * "panjang siklus" tunggal untuk dihitung. Yang benar-benar menentukan
     * adalah JARAK TERPENDEK antara dua slot yang memakai penanda sama. Itu
     * yang dicari di sini, lalu dibandingkan dengan `shelf_life` terpanjang.
     *
     * Hasilnya peringatan, bukan penolakan: berapa warna penanda yang tersedia
     * adalah barang fisik di dapur, dan server tidak berhak memutuskannya.
     *
     * @return array{repeatMinutes: int|null, longestShelfLife: int, ok: bool, message: string|null}
     */
    public function markerCycleSummary(?string $brandId): array
    {
        $longestShelfLife = $brandId === null ? 0 : (int) Menu::query()
            ->where('brand_id', $brandId)
            ->max('shelf_life');

        $repeatMinutes = $this->shortestMarkerRepeat($brandId);

        $ok = $repeatMinutes === null || $repeatMinutes >= $longestShelfLife;

        return [
            'repeatMinutes'    => $repeatMinutes,
            'longestShelfLife' => $longestShelfLife,
            'ok'               => $ok,
            'message'          => $ok ? null : sprintf(
                'Penanda yang sama berulang tiap %d menit, padahal ada menu yang bertahan %d menit. '
                . 'Dua piring berwarna sama bisa ada di belt bersamaan. Tambah penanda atau panjangkan slot.',
                $repeatMinutes,
                $longestShelfLife
            ),
        ];
    }

    /**
     * Jarak terpendek, dalam menit, antara dua slot berpenanda sama.
     *
     * NULL berarti tidak ada penanda yang dipakai dua kali — tidak ada batas
     * yang bisa dilanggar.
     */
    private function shortestMarkerRepeat(?string $brandId): ?int
    {
        if ($brandId === null) {
            return null;
        }

        $slots = TimeSlot::query()
            ->where('brand_id', $brandId)
            ->where('is_active', true)
            ->whereNotNull('time_marker_id')
            ->orderBy('start_time')
            ->get(['start_time', 'time_marker_id']);

        $lastSeen = [];
        $shortest = null;

        foreach ($slots as $slot) {
            $minute = $this->toMinutes((string) $slot->start_time);
            $markerId = $slot->time_marker_id;

            if (isset($lastSeen[$markerId])) {
                $gap = $minute - $lastSeen[$markerId];

                if ($shortest === null || $gap < $shortest) {
                    $shortest = $gap;
                }
            }

            $lastSeen[$markerId] = $minute;
        }

        return $shortest;
    }

    private function toMinutes(string $time): int
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return ((int) $hour) * 60 + (int) $minute;
    }
}
