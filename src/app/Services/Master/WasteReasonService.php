<?php

namespace App\Services\Master;

use App\Exceptions\BusinessRuleException;
use App\Models\WasteReason;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class WasteReasonService extends BaseService
{
    protected string $model = WasteReason::class;
    protected array $relations = ['category'];

    /**
     * Reason sistem (Auto-waste, Import backdate) disembunyikan kecuali
     * diminta `?with_system=1`. Dropdown dapur memakai endpoint ini tanpa
     * parameter dan tidak boleh menawarkannya — operator tidak pernah
     * memilihnya, kode yang menuliskannya.
     */
    protected function buildQuery(Request $request)
    {
        $query = parent::buildQuery($request);

        if (!$request->boolean('with_system')) {
            $query->where('is_system', false);
        }

        return $query;
    }

    /**
     * Nama reason sistem dicari kode (WasteReasonResolver), jadi tidak boleh
     * di-rename. Kategori dan deskripsinya tetap boleh diubah.
     */
    public function update($id, array $data): ?Model
    {
        $reason = $this->find($id);

        if (!$reason) {
            throw new BusinessRuleException('Alasan waste tidak ditemukan.', 404);
        }

        if ($reason->is_system && isset($data['reason_name']) && $data['reason_name'] !== $reason->reason_name) {
            throw new BusinessRuleException('Alasan waste sistem tidak bisa di-rename.', 422);
        }

        $reason->update($data);

        return $reason->load('category');
    }

    public function delete($id): ?Model
    {
        $reason = $this->find($id);

        if ($reason?->is_system) {
            throw new BusinessRuleException('Alasan waste sistem tidak bisa dihapus.', 409);
        }

        return parent::delete($id);
    }

    public function create(array $data): Model
    {
        return parent::create($data)->load('category');
    }
}
