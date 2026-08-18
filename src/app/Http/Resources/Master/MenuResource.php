<?php

namespace App\Http\Resources\Master;

use App\Http\Resources\BaseResource;

class MenuResource extends BaseResource
{
  /**
   * `code` adalah penyebab konkretnya: kode menu yang seluruhnya digit
   * ("12345") dikirim sebagai number, dan layar /admin/menus mati saat
   * memanggil `.toLowerCase()` di atasnya. `menuname` dan `description` masuk
   * dengan alasan yang sama — keduanya varchar dan boleh berisi angka saja.
   */
  protected array $textFields = ['code', 'menuname', 'description'];

  public function toArray($request) : array
    {
        // ambil default dari BaseResource
        $data = parent::toArray($request);

        // 🔥 inject relation
        $data['plate_color'] = $this->whenLoaded('plateColor', function () {
            return [
                'id' => $this->plateColor->id,
                'platename' => $this->plateColor->platename,
            ];
        });

        return $data;
    }

}
