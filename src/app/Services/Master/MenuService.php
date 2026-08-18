<?php

namespace App\Services\Master;

use App\Models\Menu;
use App\Services\BaseService;
use App\Services\Concerns\ResolvesOutletBrand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;

class MenuService extends BaseService
{
    use ResolvesOutletBrand;

    protected string $model = Menu::class;
    protected array $relations = ['plateColor', 'brand'];
    protected array $searchable = ['menuname', 'description', 'brand_id', 'is_active'];
    protected array $sortable = ['menuname', 'price', 'created_at'];

    /**
     * `?outlet_id=` disaring di sini, bukan di controller atau frontend.
     * Pemetaan outlet → brand adalah aturan bisnis, dan cuma perlu ada di satu
     * tempat; klien tidak perlu tahu bahwa brand adalah perantaranya.
     */
    protected function buildQuery(Request $request)
    {
        $query = parent::buildQuery($request);

        if ($outletId = $request->query('outlet_id')) {
            $query = $this->scopeToBrand($query, $this->brandIdForOutlet($outletId));
        }

        return $query;
    }


    public function create(array $data): Model
    {
        if (isset($data['image']) && $data['image'] instanceof UploadedFile) {

            $filename = Str::uuid() . '.' . $data['image']->getClientOriginalExtension();

            Storage::disk('s3')->putFileAs(
                'menus',
                $data['image'],
                $filename,
                'public'
            );

            $data['image'] = 'menus/' . $filename;
        }

        return parent::create($data);
    }

    public function update($id, array $data): ?Model
    {
        $menu = $this->find($id);

        if (!$menu) {
            throw new \Exception("Menu not found");
        }

        if (isset($data['image']) && $data['image'] instanceof UploadedFile) {

            // hapus image lama
            if ($menu->image) {
                Storage::disk('s3')->delete($menu->image);
            }

            $filename = Str::uuid() . '.' . $data['image']->getClientOriginalExtension();

            Storage::disk('s3')->putFileAs(
                'menus',
                $data['image'],
                $filename,
                'public'
            );

            $data['image'] = 'menus/' . $filename;
        }

        return parent::update($id, $data);
    }
}