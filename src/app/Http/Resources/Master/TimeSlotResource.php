<?php

namespace App\Http\Resources\Master;

use App\Http\Resources\BaseResource;

class TimeSlotResource extends BaseResource
{
    // `label` ("10:00-10:30") dan jamnya harus tetap teks. Lihat alasan di
    // TimeMarkerResource.
    protected array $textFields = ['label', 'start_time', 'end_time'];

    public function toArray($request): array
    {
        return array_merge(
            parent::toArray($request),
            [
                // Penanda ikut di sini supaya layar cukup satu request: planning,
                // conveyor, dan expired sama-sama butuh warna slot, dan tiga
                // request terpisah di tablet dapur berarti tiga peluang gagal.
                'marker' => $this->whenLoaded(
                    'marker',
                    fn () => $this->marker ? new TimeMarkerResource($this->marker) : null
                ),
            ]
        );
    }
}
