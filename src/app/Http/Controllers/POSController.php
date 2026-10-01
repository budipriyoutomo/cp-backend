<?php

namespace App\Http\Controllers;

    use Illuminate\Http\Request;
    use App\Http\Resources\POS\POSComparisonResource;

    use App\Services\POSService;

    class POSController extends BaseApiController
    {
        public function __construct(
            protected POSService $service,
        ) {}
         
        /*
        |--------------------------------------------------------------------------
        | GET POS DATA FOR Closing
        |--------------------------------------------------------------------------
        */

        public function getposData(Request $request)
        {
            // Endpoint ini sebelumnya tidak memvalidasi apa pun: `outletId`
            // langsung masuk ke `where('outlet_id', ...)`, dan `outlets.id`
            // bertipe uuid di PostgreSQL. Nilai yang bentuknya salah — atau
            // hilang sama sekali — menjawab 500 "invalid input syntax for type
            // uuid", bukan 422. Aturannya disamakan dengan endpoint production
            // di sebelahnya.
            $request->validate([
                'outletId' => ['required', 'uuid'],
                'date'     => ['required', 'date'],
            ]);

            // Gerbang (carry-over + piring belum final) dan perbandingannya
            // ada di POSService.
            $comparison = $this->service->reconcile(
                $request->outletId,
                $request->date
            );

            return $this->success(POSComparisonResource::collection($comparison));
        }

    }