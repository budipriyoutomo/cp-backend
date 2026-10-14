<?php

namespace App\Models;

/**
 * Kategori di atas waste reason. `code` dikirim ke BI dan menjadi kunci di
 * sana, jadi ia stabil: WasteCategoryService menolak mengubahnya setelah
 * kategori dipakai.
 */
class WasteCategory extends BaseModel
{
    /** Kategori bawaan — semua reason dipetakan ke sini saat fitur naik. */
    public const DEFAULT_CODE = 'DEFAULT_WASTE';

    /**
     * Dicadangkan untuk waste yang kategorinya tidak bisa dipastikan (data
     * sebelum fitur ini, teks reason yang tidak ada di master). Bukan baris di
     * tabel — tidak boleh dibuat lewat API.
     */
    public const UNCATEGORIZED_CODE = 'UNCATEGORIZED';
    public const UNCATEGORIZED_NAME = 'Belum dikategorikan';

    protected $table = 'waste_categories';

    protected $fillable = [
        'code',
        'category_name',
        'description',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function reasons()
    {
        return $this->hasMany(WasteReason::class, 'waste_category_id');
    }
}
