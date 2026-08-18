<?php

namespace App\Services\Master;

use App\Models\PlateColors;
use App\Services\BaseService;
use App\Services\Concerns\ResolvesOutletBrand;
use Illuminate\Http\Request;

class PlateColorService extends BaseService
{
    use ResolvesOutletBrand;

    protected string $model = PlateColors::class;
    protected array $relations = ['brand'];
    protected array $searchable = ['platename', 'brand_id', 'is_active'];
    protected array $sortable = ['platename', 'price', 'created_at'];

    /**
     * Lihat MenuService: pemetaan outlet → brand hidup di service, bukan di
     * klien. Dengan warna piring ini lebih penting lagi — warna adalah unit
     * harga, jadi menampilkan warna brand lain berarti menampilkan harga yang
     * salah.
     */
    protected function buildQuery(Request $request)
    {
        $query = parent::buildQuery($request);

        if ($outletId = $request->query('outlet_id')) {
            $query = $this->scopeToBrand($query, $this->brandIdForOutlet($outletId));
        }

        return $query;
    }
}
