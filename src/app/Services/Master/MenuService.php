<?php

namespace App\Services\Master;

use App\Models\Menu;
use App\Services\BaseService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;

class MenuService extends BaseService
{
    protected string $model = Menu::class;

    public function create(array $data): Model
    {
        if (isset($data['image']) && $data['image'] instanceof UploadedFile) {

            $filename = Str::uuid() . '.' . $data['image']->getClientOriginalExtension();

            Storage::disk('s3')->put(
                'menus',
                $data['image'],
                $filename
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

            Storage::disk('s3')->put(
                'menus',
                $data['image'],
                $filename
            );

            $data['image'] = 'menus/' . $filename;
        }

        return parent::update($id, $data);
    }
}