<?php

namespace App\Http\Requests\Master;

use App\Http\Requests\BaseRequest;
use App\Http\Requests\Concerns\ScopedToBrand;
use App\Models\TimeMarker;
use App\Models\TimeSlot;
use Illuminate\Contracts\Validation\Validator;

class TimeSlotRequest extends BaseRequest
{
    use ScopedToBrand;

    protected function prepareForValidation()
    {
        parent::prepareForValidation();

        $this->fillSoleBrand();

        // "10:00" dan "10:00:00" sama-sama diterima dari klien, tapi hanya satu
        // bentuk yang boleh masuk basis data. Unique index (brand_id,
        // start_time) dan label kanonis sama-sama bergantung pada itu — dua
        // ejaan untuk jam yang sama berarti dua baris yang mestinya bentrok.
        $this->merge([
            'start_time' => $this->normalizeTime($this->input('start_time')),
            'end_time'   => $this->normalizeTime($this->input('end_time')),
        ]);
    }

    private function normalizeTime(mixed $value): mixed
    {
        if (! is_string($value) || ! preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $value)) {
            return $value;
        }

        return substr($value, 0, 5) . ':00';
    }

    /** Lihat alasan "wajib, bukan brandIdRules()" di TimeMarkerRequest. */
    private function brandRules(): array
    {
        return ['required', 'uuid', 'exists:brands,id'];
    }

    private function sharedRules(): array
    {
        return [
            'brand_id'       => $this->brandRules(),
            'start_time'     => ['required', 'date_format:H:i:s'],
            'end_time'       => ['required', 'date_format:H:i:s'],
            'time_marker_id' => ['nullable', 'uuid', 'exists:time_markers,id'],
            'sort_order'     => ['nullable', 'integer', 'min:0'],
        ];
    }

    protected function rulesForCreate(): array
    {
        return $this->sharedRules();
    }

    protected function rulesForUpdate(): array
    {
        return $this->sharedRules();
    }

    /**
     * Tiga aturan yang tidak bisa dijaga index maupun rule bawaan.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->assertEndAfterStart($validator);
            $this->assertMarkerBelongsToSameBrand($validator);
            $this->assertDoesNotOverlap($validator);
        });
    }

    private function assertEndAfterStart(Validator $validator): void
    {
        if ($this->input('end_time') <= $this->input('start_time')) {
            $validator->errors()->add('end_time', 'Jam selesai harus lebih besar dari jam mulai.');
        }
    }

    /**
     * Penanda milik brand lain akan menampilkan warna yang tidak pernah dipakai
     * outlet ini. Sama seperti plan yang memuat plate color brand lain —
     * ditolak, bukan ditebak.
     */
    private function assertMarkerBelongsToSameBrand(Validator $validator): void
    {
        $markerId = $this->input('time_marker_id');

        if (! $markerId) {
            return;
        }

        $markerBrandId = TimeMarker::whereKey($markerId)->value('brand_id');

        if ($markerBrandId !== null && $markerBrandId !== $this->input('brand_id')) {
            $validator->errors()->add('time_marker_id', 'Penanda itu milik brand lain.');
        }
    }

    /**
     * Unique index hanya menangkap jam mulai yang sama persis. Tumpang tindih
     * yang lebih halus (10:00-11:00 melawan 10:30-11:30) lolos dari index, dan
     * akibatnya bukan sekadar tampilan: satu jam produksi bisa jatuh ke dua
     * slot sekaligus, jadi penanda sebuah piring bergantung pada baris mana
     * yang kebetulan ditemukan duluan.
     *
     * Baris soft-deleted dikecualikan, sama seperti partial unique index-nya.
     */
    private function assertDoesNotOverlap(Validator $validator): void
    {
        $overlap = TimeSlot::query()
            ->where('brand_id', $this->input('brand_id'))
            ->when($this->route('id'), fn ($q, $id) => $q->whereKeyNot($id))
            ->where('start_time', '<', $this->input('end_time'))
            ->where('end_time', '>', $this->input('start_time'))
            ->first();

        if ($overlap) {
            $validator->errors()->add(
                'start_time',
                "Jamnya bertabrakan dengan slot {$overlap->label} yang sudah ada."
            );
        }
    }

    public function messages(): array
    {
        return [
            'start_time.date_format' => 'Jam mulai harus dalam bentuk HH:MM.',
            'end_time.date_format'   => 'Jam selesai harus dalam bentuk HH:MM.',
        ];
    }
}
