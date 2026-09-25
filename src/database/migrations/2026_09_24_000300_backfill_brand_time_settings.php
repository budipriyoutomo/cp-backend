<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Mengisi setelan waktu bawaan untuk brand yang sudah ada.
 *
 * Tanpa langkah ini, hari pertama kode baru naik berarti layar planning kosong
 * dan penanda di conveyor hilang — dua layar yang dipakai dapur setiap hari.
 * Yang diisi persis sama dengan yang dulu dikunci mati di frontend, jadi tidak
 * ada yang berubah di mata operator; yang berubah hanya dari mana angkanya
 * datang.
 *
 * Nilai bawaannya:
 * - 22 slot, 10:00 sampai 21:00, kelipatan 30 menit
 * - 5 penanda: Biru, Hitam, Merah, Kuning, Hijau
 * - penanda dipasang berputar ke slot, persis perilaku lama
 *
 * Idempoten: brand yang sudah punya slot atau penanda dilewati sepenuhnya.
 * Jadi migration ini aman dijalankan ulang, dan tidak akan menimpa setelan yang
 * sudah disesuaikan admin.
 *
 * Tidak ada down(). Membalikkannya berarti menghapus setelan yang mungkin sudah
 * diubah admin sesudahnya, dan tabelnya sendiri sudah dibuang oleh down() milik
 * migration 000000/000100.
 */
return new class extends Migration
{
    /** Persis TIME_SLOT_COLORS lama di production-planning.tsx. */
    private const DEFAULT_MARKERS = [
        ['label' => 'Biru',   'color_hex' => '#3B82F6'],
        ['label' => 'Hitam',  'color_hex' => '#1F2937'],
        ['label' => 'Merah',  'color_hex' => '#EF4444'],
        ['label' => 'Kuning', 'color_hex' => '#FACC15'],
        ['label' => 'Hijau',  'color_hex' => '#22C55E'],
    ];

    private const SLOT_START_HOUR = 10;
    private const SLOT_END_HOUR = 21;
    private const SLOT_MINUTES = 30;

    /**
     * Peta warna lama dari plate-color-badge.tsx, ditambah padanan Indonesia
     * karena instalasi ini memakai nama seperti "Merah" dan "Emas" — nama yang
     * tidak pernah ada di peta lama, jadi selama ini jatuh ke abu-abu netral.
     */
    private const PLATE_COLOR_HEX = [
        'white'         => '#F3F4F6',
        'putih'         => '#F3F4F6',
        'blue'          => '#3B82F6',
        'biru'          => '#3B82F6',
        'pink'          => '#EC4899',
        'merah muda'    => '#EC4899',
        'black'         => '#18181B',
        'hitam'         => '#18181B',
        'red'           => '#EF4444',
        'merah'         => '#EF4444',
        'gold'          => '#EAB308',
        'emas'          => '#EAB308',
        'choco motive'  => '#78350F',
        'choco'         => '#78350F',
        'coklat'        => '#78350F',
        'cokelat'       => '#78350F',
        'yellow'        => '#FACC15',
        'kuning'        => '#FACC15',
        'silver'        => '#9CA3AF',
        'perak'         => '#9CA3AF',
        'green'         => '#22C55E',
        'hijau'         => '#22C55E',
        'purple'        => '#A855F7',
        'ungu'          => '#A855F7',
        'orange'        => '#F97316',
        'jingga'        => '#F97316',
    ];

    public function up(): void
    {
        $brands = DB::table('brands')->whereNull('deleted_at')->pluck('id');

        foreach ($brands as $brandId) {
            $markerIds = $this->seedMarkers($brandId);

            $this->seedSlots($brandId, $markerIds);
            $this->warnIfCycleShorterThanShelfLife($brandId, count($markerIds));
        }

        $this->fillPlateColorHex();
    }

    public function down(): void
    {
        //
    }

    /**
     * @return list<string> id penanda, urut sort_order
     */
    private function seedMarkers(string $brandId): array
    {
        $existing = DB::table('time_markers')
            ->where('brand_id', $brandId)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->pluck('id')
            ->all();

        if (! empty($existing)) {
            return $existing;
        }

        $now = now();
        $rows = [];
        $ids = [];

        foreach (self::DEFAULT_MARKERS as $index => $marker) {
            $id = (string) Str::uuid();
            $ids[] = $id;

            $rows[] = [
                'id'         => $id,
                'brand_id'   => $brandId,
                'label'      => $marker['label'],
                'color_hex'  => $marker['color_hex'],
                'sort_order' => $index,
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('time_markers')->insert($rows);

        return $ids;
    }

    /**
     * @param list<string> $markerIds
     */
    private function seedSlots(string $brandId, array $markerIds): void
    {
        $alreadyHasSlots = DB::table('time_slots')
            ->where('brand_id', $brandId)
            ->whereNull('deleted_at')
            ->exists();

        if ($alreadyHasSlots) {
            return;
        }

        $now = now();
        $rows = [];
        $index = 0;

        $minute = self::SLOT_START_HOUR * 60;
        $lastMinute = self::SLOT_END_HOUR * 60;

        while ($minute < $lastMinute) {
            $end = $minute + self::SLOT_MINUTES;

            $rows[] = [
                'id'             => (string) Str::uuid(),
                'brand_id'       => $brandId,
                'start_time'     => $this->asTime($minute),
                'end_time'       => $this->asTime($end),
                // Berputar, persis perilaku lama. Admin bebas mengubahnya
                // setelah ini — itu justru gunanya kolom ini.
                'time_marker_id' => $markerIds === [] ? null : $markerIds[$index % count($markerIds)],
                'sort_order'     => $index,
                'is_active'      => true,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];

            $minute = $end;
            $index++;
        }

        DB::table('time_slots')->insert($rows);
    }

    private function asTime(int $minuteOfDay): string
    {
        return sprintf('%02d:%02d:00', intdiv($minuteOfDay, 60), $minuteOfDay % 60);
    }

    /**
     * Inti masalah yang melahirkan fitur ini: siklus penanda yang lebih pendek
     * dari umur piring membuat dua batch berwarna sama ada di belt bersamaan.
     * Dengan bawaan 5 penanda x 30 menit = 150 menit, dan `shelf_life` 180
     * menit yang dipakai sebagian besar menu, itu memang terjadi.
     *
     * Migration ini sengaja TIDAK menambah penanda keenam sendiri: berapa warna
     * yang tersedia adalah urusan fisik di dapur, bukan hal yang boleh ditebak
     * basis data. Jadi keadaannya dilaporkan, lalu admin yang memutuskan.
     */
    private function warnIfCycleShorterThanShelfLife(string $brandId, int $markerCount): void
    {
        if ($markerCount === 0) {
            return;
        }

        $longestShelfLife = (int) DB::table('menus')
            ->where('brand_id', $brandId)
            ->whereNull('deleted_at')
            ->max('shelf_life');

        $cycleMinutes = $markerCount * self::SLOT_MINUTES;

        if ($longestShelfLife <= $cycleMinutes) {
            return;
        }

        $brandName = DB::table('brands')->where('id', $brandId)->value('name') ?? $brandId;

        Log::warning(
            "[time-settings] Brand {$brandName}: siklus penanda {$cycleMinutes} menit "
            . "({$markerCount} penanda x " . self::SLOT_MINUTES . " menit), tapi shelf_life "
            . "terpanjang {$longestShelfLife} menit. Dua batch berwarna sama bisa ada di belt "
            . 'bersamaan. Tambah penanda lewat Admin > Setelan Brand.'
        );
    }

    /**
     * Hanya mengisi yang masih NULL, dan hanya nama yang dikenal. Nama di luar
     * daftar dibiarkan kosong supaya badge-nya jatuh ke warna cadangan —
     * kosong dan jujur, bukan terisi tebakan.
     */
    private function fillPlateColorHex(): void
    {
        $rows = DB::table('plate_colors')
            ->whereNull('deleted_at')
            ->whereNull('color_hex')
            ->get(['id', 'platename']);

        foreach ($rows as $row) {
            $key = Str::lower(trim((string) $row->platename));

            if (! isset(self::PLATE_COLOR_HEX[$key])) {
                continue;
            }

            DB::table('plate_colors')
                ->where('id', $row->id)
                ->update(['color_hex' => self::PLATE_COLOR_HEX[$key]]);
        }
    }
};
