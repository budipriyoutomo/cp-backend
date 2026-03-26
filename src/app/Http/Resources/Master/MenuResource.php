<?php

namespace App\Http\Resources\Master;

use App\Http\Resources\BaseResource;

class MenuResource extends BaseResource
{ 
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
