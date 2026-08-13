<?php

namespace Database\Seeders;

use App\Models\WasteReason;
use Illuminate\Database\Seeder;

/**
 * Waste reasons drive the dropdown on the expired-items screen and feed
 * `byReason` in the waste analysis report. Safe to re-run: matched by name.
 */
class WasteReasonSeeder extends Seeder
{
    public function run(): void
    {
        $reasons = [
            ['Expired', 'Piring melewati shelf life dan tidak terjual'],
            ['Tabrakan Belt',     'Piring jatuh, tumpah, atau rusak saat penyajian'],
            ['Berkurang Qty',    'Piring dikurangi jumlahnya karena kesalahan pencatatan'],
            ['Serangga',    'Piring terkontaminasi serangga'], 
            ['Lainnya',     'Alasan lain yang tidak tercakup di atas'],
        ];

        foreach ($reasons as [$name, $description]) {
            WasteReason::updateOrCreate(
                ['reason_name' => $name],
                ['description' => $description, 'is_active' => true]
            );
        }
    }
}
