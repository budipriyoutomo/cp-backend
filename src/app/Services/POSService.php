<?php

namespace App\Services;

use App\Models\POSData;
use App\Models\PlateColors;
use App\Models\Outlet;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class POSService
{
    public function __construct(
        public POSData $posData
    ) {}

    public function getPosDataForClosing($outletId, $date)
    {
        return $this->posData
            ->where('outlet_id', $outletId)
            ->where('date', $date)
            ->with('plateColor')
            ->get();
    }

    public function storeFromEvent(array $data)
    {
        return DB::transaction(function () use ($data) {

            $plateName  = $this->normalize($data['platecolor']);
            $outletCode = $this->normalize($data['outlet']);

            // Outlet diresolusi DULUAN, dan itu disengaja: outlet menentukan
            // brand, dan brand menentukan "Merah" yang mana. Payload POS hanya
            // membawa nama warna, jadi tanpa outlet nama itu tidak cukup untuk
            // menunjuk satu baris.
            $outlet = Outlet::whereRaw('LOWER(TRIM(code)) = ?', [$outletCode])->first();

            if (!$outlet) {
                throw new \Exception("Outlet not found: {$outletCode}");
            }

            $plate = $this->resolvePlateColor($plateName, $outlet);

            // cek existing (idempotent)
            $existing = $this->posData
                ->where('outlet_id', $outlet->id)
                ->where('plate_color_id', $plate->id)
                ->where('date', $data['date'])
                ->first();

            // UPSERT: sold ditimpa, bukan dilewati
            if ($existing) {
                $existing->update([
                    'sold' => $data['sold']
                ]);

                return [
                    'status' => 'updated',
                    'id' => $existing->id
                ];
            }

            $pos = $this->posData->create([
                'id' => (string) Str::uuid(),
                'plate_color_id' => $plate->id,
                'outlet_id' => $outlet->id,
                'date' => $data['date'],
                'sold' => $data['sold'],
            ]);

            return [
                'status' => 'created',
                'id' => $pos->id
            ];
        });
    }

    /**
     * Nama warna dicocokkan DI DALAM brand outlet.
     *
     * Sejak plate color jadi per-brand, dua brand boleh sama-sama punya "Merah"
     * dengan harga berbeda. Pencarian global yang lama akan mengambil mana pun
     * yang kebetulan lebih dulu di tabel — dan yang salah bukan cuma label,
     * tapi angka penjualan yang masuk ke rekonsiliasi.
     *
     * Ada satu kelonggaran yang disengaja: warna yang `brand_id`-nya masih NULL
     * (belum di-backfill) tetap diterima selama tidak ambigu. Tanpa itu, basis
     * data yang punya lebih dari satu brand — di mana migrasi Fase 2 sengaja
     * membiarkan plate_colors NULL — akan berhenti menerima data POS sama
     * sekali.
     *
     * Apa pun yang ambigu dilempar, bukan ditebak. Pemanggilnya (consumer AMQP
     * dan pos:replay-failed) memarkir payload ke failed_pos_messages, jadi
     * lemparan di sini berarti tertunda dan bisa diputar ulang — bukan hilang.
     */
    private function resolvePlateColor(string $plateName, Outlet $outlet): PlateColors
    {
        $candidates = PlateColors::whereRaw('LOWER(TRIM(platename)) = ?', [$plateName])->get();

        if ($candidates->isEmpty()) {
            throw new \Exception("Plate color not found: {$plateName}");
        }

        if ($outlet->brand_id) {
            $inBrand = $candidates->where('brand_id', $outlet->brand_id);

            if ($inBrand->count() === 1) {
                return $inBrand->first();
            }

            if ($inBrand->count() > 1) {
                throw new \Exception($this->ambiguous($plateName, $outlet, $inBrand->count()));
            }
        }

        $unassigned = $candidates->whereNull('brand_id');

        if ($unassigned->count() === 1) {
            return $unassigned->first();
        }

        if ($unassigned->count() > 1) {
            throw new \Exception($this->ambiguous($plateName, $outlet, $unassigned->count()));
        }

        // Outlet tanpa brand + satu-satunya kandidat: tidak ada yang bisa salah
        // dipilih. Outlet DENGAN brand tidak boleh sampai sini — kandidat
        // tunggal milik brand lain justru kasus yang paling berbahaya.
        if (!$outlet->brand_id && $candidates->count() === 1) {
            return $candidates->first();
        }

        throw new \Exception(
            "Plate color not found: {$plateName} untuk brand outlet {$outlet->code}. "
            . "Nama itu ada di master, tapi milik brand lain. "
            . "Tambahkan '{$plateName}' ke brand outlet ini lalu jalankan pos:replay-failed."
        );
    }

    private function ambiguous(string $plateName, Outlet $outlet, int $count): string
    {
        return "Plate color ambiguous: {$plateName} cocok dengan {$count} baris untuk outlet "
            . "{$outlet->code}. Rapikan master plate color lalu jalankan pos:replay-failed.";
    }

    private function normalize(string $value): string
    {
        $clean = strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $value)));

        return preg_replace('/\s+/u', ' ', $clean);
    }
}
