<?php

namespace App\Services\Master;

use App\Exceptions\BusinessRuleException;
use App\Models\WasteCategory;
use App\Models\WasteRecord;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class WasteCategoryService extends BaseService
{
    protected string $model = WasteCategory::class;
    protected array $searchable = ['code', 'category_name', 'is_active'];
    protected array $sortable = ['sort_order', 'code', 'category_name', 'created_at'];

    protected function buildQuery(Request $request)
    {
        return parent::buildQuery($request)
            ->withCount('reasons')
            ->orderBy('sort_order')
            ->orderBy('category_name');
    }

    /**
     * `code` adalah kunci kategori di BI. Setelah ada reason atau catatan waste
     * yang menunjuknya, mengubahnya berarti data lama dan baru di BI terpecah
     * menjadi dua kategori — jadi ditolak.
     */
    public function update($id, array $data): ?Model
    {
        $category = $this->find($id);

        if (!$category) {
            throw new BusinessRuleException('Kategori waste tidak ditemukan.', 404);
        }

        if (isset($data['code']) && $data['code'] !== $category->code && $this->isUsed($category)) {
            throw new BusinessRuleException(
                'Kode kategori tidak bisa diubah karena sudah dipakai. Kode ini menjadi kunci data di BI.',
                422
            );
        }

        $category->update($data);

        return $category->loadCount('reasons');
    }

    /**
     * Kategori yang masih ditunjuk reason tidak boleh hilang — reason itu akan
     * jatuh ke "belum dikategorikan" tanpa ada yang memutuskannya. Pindahkan
     * reason-nya dulu.
     */
    public function delete($id): ?Model
    {
        $category = $this->find($id);

        if (!$category) {
            throw new BusinessRuleException('Kategori waste tidak ditemukan.', 404);
        }

        $count = $category->reasons()->count();

        if ($count > 0) {
            throw new BusinessRuleException(
                "Kategori masih dipakai {$count} alasan waste. Pindahkan alasannya ke kategori lain dulu.",
                409
            );
        }

        return parent::delete($id);
    }

    public function create(array $data): Model
    {
        return parent::create($data)->loadCount('reasons');
    }

    private function isUsed(WasteCategory $category): bool
    {
        return $category->reasons()->withTrashed()->exists()
            || WasteRecord::withTrashed()->where('waste_category_id', $category->id)->exists();
    }
}
