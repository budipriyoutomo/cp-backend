<?php

namespace Database\Seeders;

use App\Models\WasteCategory;
use App\Models\WasteReason;
use Illuminate\Database\Seeder;

/**
 * Waste reasons drive the dropdown on the expired-items screen and feed
 * `byReason` in the waste analysis report. Safe to re-run: matched by name.
 *
 * Semua dipetakan ke kategori bawaan (WasteCategorySeeder harus jalan dulu).
 * Kategori yang sudah diubah admin tidak ditimpa.
 */
class WasteReasonSeeder extends Seeder
{
    public function run(): void
    {
        $categoryId = WasteCategory::where('code', WasteCategory::DEFAULT_CODE)->value('id');

        $reasons = [
            ['Expired', 'Piring melewati shelf life dan tidak terjual'],
            ['Tabrakan Belt',     'Piring jatuh, tumpah, atau rusak saat penyajian'],
            ['Berkurang Qty',    'Piring dikurangi jumlahnya karena kesalahan pencatatan'],
            ['Serangga',    'Piring terkontaminasi serangga'],
            ['Lainnya',     'Alasan lain yang tidak tercakup di atas'],
        ];

        foreach ($reasons as [$name, $description]) {
            $this->upsert($name, $description, $categoryId, false);
        }

        foreach (WasteReason::SYSTEM_REASONS as $name => $description) {
            $this->upsert($name, $description, $categoryId, true);
        }
    }

    private function upsert(string $name, string $description, ?string $categoryId, bool $isSystem): void
    {
        $reason = WasteReason::withTrashed()->firstOrNew(['reason_name' => $name]);

        $reason->fill([
            'description' => $description,
            'is_active'   => true,
            'is_system'   => $isSystem,
        ]);
        $reason->waste_category_id ??= $categoryId;
        $reason->deleted_at = null;
        $reason->save();
    }
}
