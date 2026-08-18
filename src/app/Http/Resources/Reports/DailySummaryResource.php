<?php

namespace App\Http\Resources\Reports;

use App\Http\Resources\BaseResource;

class DailySummaryResource extends BaseResource
{
    /**
     * Hidden fields
     */
    protected array $hiddenFields = [];

    /**
     * Numeric fields
     */
    protected array $moneyFields = [
        'totalPOS',
        'totalProduction',
        'totalWaste',
        'totalAdjustment',
        'totalCompensation',
        'totalSelisih',

        'posSold',
        'productionSold',
        'productionWaste',

        'adjustment',
        'compensation',
        'selisih',
    ];

    /**
     * Resource ini memanggil `formatValue()` langsung per-field, jadi daftar ini
     * yang menjaganya — bukan `autoDetect()`.
     *
     * `plateColorName` adalah yang nyata: warna piring bernama angka akan
     * dikirim sebagai number, dan laporan harian menampilkannya sebagai teks.
     * `date` sengaja TIDAK di sini — ia bergantung pada cabang `isDate()`.
     */
    protected array $textFields = [
        'outletId',
        'outletName',
        'plateColorId',
        'plateColorName',
    ];

    /**
     * Transform resource
     */
    public function toArray($request): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | HEADER
            |--------------------------------------------------------------------------
            */
            'outletId' => $this->formatValue(
                'outletId',
                data_get($this->resource, 'outletId')
            ),

            'outletName' => $this->formatValue(
                'outletName',
                data_get($this->resource, 'outletName')
            ),

            'date' => $this->formatValue(
                'date',
                data_get($this->resource, 'date')
            ),

            /*
            |--------------------------------------------------------------------------
            | TOTALS
            |--------------------------------------------------------------------------
            */
            'totalPOS' => $this->formatValue(
                'totalPOS',
                data_get($this->resource, 'totalPOS')
            ),

            'totalProduction' => $this->formatValue(
                'totalProduction',
                data_get($this->resource, 'totalProduction')
            ),

            'totalWaste' => $this->formatValue(
                'totalWaste',
                data_get($this->resource, 'totalWaste')
            ),

            'totalAdjustment' => $this->formatValue(
                'totalAdjustment',
                data_get($this->resource, 'totalAdjustment')
            ),

            'totalCompensation' => $this->formatValue(
                'totalCompensation',
                data_get($this->resource, 'totalCompensation')
            ),

            'totalSelisih' => $this->formatValue(
                'totalSelisih',
                data_get($this->resource, 'totalSelisih')
            ),

            /*
            |--------------------------------------------------------------------------
            | ITEMS
            |--------------------------------------------------------------------------
            */
            'items' => collect(
                data_get($this->resource, 'items', [])
            )->map(function ($item) {

                return [

                    'plateColorId' => $this->formatValue(
                        'plateColorId',
                        data_get($item, 'plateColorId')
                    ),

                    'plateColorName' => $this->formatValue(
                        'plateColorName',
                        data_get($item, 'plateColorName')
                    ),

                    'posSold' => $this->formatValue(
                        'posSold',
                        data_get($item, 'posSold')
                    ),

                    'productionSold' => $this->formatValue(
                        'productionSold',
                        data_get($item, 'productionSold')
                    ),

                    'productionWaste' => $this->formatValue(
                        'productionWaste',
                        data_get($item, 'productionWaste')
                    ),

                    'adjustment' => $this->formatValue(
                        'adjustment',
                        data_get($item, 'adjustment')
                    ),

                    'compensation' => $this->formatValue(
                        'compensation',
                        data_get($item, 'compensation')
                    ),

                    'selisih' => $this->formatValue(
                        'selisih',
                        data_get($item, 'selisih')
                    ),
                ];
            })->values(),
        ];
    }
}