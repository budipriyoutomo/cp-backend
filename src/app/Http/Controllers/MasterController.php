<?php

namespace App\Http\Controllers;
 
use App\Http\Requests\Master\PlateColorRequest;
use App\Http\Resources\Master\PlateColorResource;
use App\Http\Requests\Master\MenuRequest;
use App\Http\Resources\Master\MenuResource;
use App\Http\Requests\Master\OutletRequest;
use App\Http\Resources\Master\OutletResource;
use App\Http\Requests\Master\WasteReasonRequest;
use App\Http\Resources\Master\WasteReasonResource;
use App\Http\Requests\Master\BrandRequest;
use App\Http\Resources\Master\BrandResource;
use App\Http\Requests\Master\TimeMarkerRequest;
use App\Http\Resources\Master\TimeMarkerResource;
use App\Http\Requests\Master\TimeSlotRequest;
use App\Http\Resources\Master\TimeSlotResource;


use App\Services\MasterService;
use Symfony\Component\HttpFoundation\Request;

class MasterController extends BaseApiController
{
    public function __construct(
        private MasterService $service
    ) {}
  
    // ======================================================
    // PLATE COLOR METHODS
    // ======================================================

    public function platecolorindex(Request $request)
    {
        return $this->resource(
            PlateColorResource::collection($this->service->plateColor->list($request))
        );
    }

    public function platecolorstore(PlateColorRequest $request)
    {
        return $this->resource(
            new PlateColorResource(
                $this->service->plateColor->create($request->validated())
            ),
            'Plate color created',
            201
        );
    }

    public function platecolorupdate(PlateColorRequest $request, $id)
    {
        return $this->resource(
            new PlateColorResource(
                $this->service->plateColor->update($id, $request->validated())
            ),
            'Plate color updated'
        );
    }

    public function platecolordestroy($id)
    {
        $this->service->plateColor->delete($id);
        return $this->success(null, 'Plate color deleted');
    }

    
    // ======================================================
    // MENU METHODS
    // ======================================================

    public function menuindex(Request $request)
    {
        // BaseService membaca filter dari $request->query(). merge() menulis ke
        // "input source", dan untuk request dengan Content-Type application/json
        // itu bag JSON — bukan bag query. Jadi filter ini diam-diam tidak
        // berlaku persis pada klien yang mengirim header tersebut. Tulis
        // langsung ke bag yang benar-benar dibaca.
        $request->query->set('is_active', 1);

       return $this->resource(
            MenuResource::collection($this->service->menu->all($request))
        ); 
    }

    public function menustore(MenuRequest $request)
    {
        $data = $request->validated();

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image');
        }
        
        return $this->resource(
            new MenuResource(
                $this->service->menu->create($data)
            ),
            'Menu created',
            201
        );
    }

    public function menuupdate(MenuRequest $request, $id)
    {
        return $this->resource(
            new MenuResource(
                $this->service->menu->update($id, $request->validated())
            ),
            'Menu updated'
        );
    }

    public function menudestroy($id)
    {
        $this->service->menu->delete($id);
        return $this->success(null, 'Menu deleted');
    }

    
    // ======================================================
    // OUTLET METHODS
    // ======================================================

    public function outletindex(Request $request)
    {
        return $this->resource(
            OutletResource::collection($this->service->outlet->list($request))
        );
    }

    public function outletstore(OutletRequest $request)
    {
        return $this->resource(
            new OutletResource(
                $this->service->outlet->create($request->validated())
            ),
            'Outlet created',
            201
        );
    }

    public function outletupdate(OutletRequest $request, $id)
    {
        return $this->resource(
            new OutletResource(
                $this->service->outlet->update($id, $request->validated())
            ),
            'Outlet updated'
        );
    }

    public function outletdestroy($id)
    {
        $this->service->outlet->delete($id);
        return $this->success(null, 'Outlet deleted');
    }

    // ======================================================
    // BRAND METHODS
    // ======================================================

    public function brandindex(Request $request)
    {
        return $this->resource(
            BrandResource::collection($this->service->brand->list($request))
        );
    }

    /**
     * Master lain belum punya show(), padahal Route::crud sudah mendaftarkan
     * GET /{id} untuk semuanya — jadi endpoint itu 500 di platecolor/menu/
     * outlet/waste-reason. Brand tidak ikut mewarisi lubang itu.
     */
    public function brandshow($id)
    {
        $brand = $this->service->brand->find($id);

        if (!$brand) {
            return $this->error('Brand not found', 404);
        }

        return $this->resource(new BrandResource($brand));
    }

    public function brandstore(BrandRequest $request)
    {
        return $this->resource(
            new BrandResource(
                $this->service->brand->create($request->validated())
            ),
            'Brand created',
            201
        );
    }

    public function brandupdate(BrandRequest $request, $id)
    {
        return $this->resource(
            new BrandResource(
                $this->service->brand->update($id, $request->validated())
            ),
            'Brand updated'
        );
    }

    public function branddestroy($id)
    {
        $this->service->brand->delete($id);
        return $this->success(null, 'Brand deleted');
    }

    // ======================================================
    // WASTE REASON METHODS
    // ======================================================

    public function wastereasonindex(Request $request)
    {
        return $this->resource(
            WasteReasonResource::collection($this->service->wasteReason->list($request))
        );
    }

    public function wastereasonstore(WasteReasonRequest $request)
    {
        return $this->resource(
            new WasteReasonResource(
                $this->service->wasteReason->create($request->validated())
            ),
            'Waste reason created',
            201
        );
    }

    public function wastereasonupdate(WasteReasonRequest $request, $id)
    {
        return $this->resource(
            new WasteReasonResource(
                $this->service->wasteReason->update($id, $request->validated())
            ),
            'Waste reason updated'
        );
    }

    public function wastereasondestroy($id)
    {
        $this->service->wasteReason->delete($id);
        return $this->success(null, 'Waste reason deleted');
    }

    // ======================================================
    // TIME MARKER METHODS
    //
    // `show()` ditulis untuk keduanya, tidak seperti master lain: Route::crud
    // mendaftarkan GET /{id} untuk semuanya, dan tanpa method-nya endpoint itu
    // menjawab 500. Lihat komentar di brandshow().
    // ======================================================

    public function timemarkerindex(Request $request)
    {
        return $this->resource(
            TimeMarkerResource::collection($this->service->timeMarker->list($request))
        );
    }

    public function timemarkershow($id)
    {
        $marker = $this->service->timeMarker->find($id);

        if (!$marker) {
            return $this->error('Time marker not found', 404);
        }

        return $this->resource(new TimeMarkerResource($marker));
    }

    public function timemarkerstore(TimeMarkerRequest $request)
    {
        return $this->resource(
            new TimeMarkerResource(
                $this->service->timeMarker->create($request->validated())
            ),
            'Time marker created',
            201
        );
    }

    public function timemarkerupdate(TimeMarkerRequest $request, $id)
    {
        return $this->resource(
            new TimeMarkerResource(
                $this->service->timeMarker->update($id, $request->validated())
            ),
            'Time marker updated'
        );
    }

    public function timemarkerdestroy($id)
    {
        $this->service->timeMarker->delete($id);
        return $this->success(null, 'Time marker deleted');
    }

    // ======================================================
    // TIME SLOT METHODS
    // ======================================================

    public function timeslotindex(Request $request)
    {
        return $this->resource(
            TimeSlotResource::collection($this->service->timeSlot->list($request))
        );
    }

    public function timeslotshow($id)
    {
        $slot = $this->service->timeSlot->find($id);

        if (!$slot) {
            return $this->error('Time slot not found', 404);
        }

        return $this->resource(new TimeSlotResource($slot));
    }

    public function timeslotstore(TimeSlotRequest $request)
    {
        return $this->resource(
            new TimeSlotResource(
                $this->service->timeSlot->create($request->validated())
            ),
            'Time slot created',
            201
        );
    }

    public function timeslotupdate(TimeSlotRequest $request, $id)
    {
        return $this->resource(
            new TimeSlotResource(
                $this->service->timeSlot->update($id, $request->validated())
            ),
            'Time slot updated'
        );
    }

    public function timeslotdestroy($id)
    {
        $this->service->timeSlot->delete($id);
        return $this->success(null, 'Time slot deleted');
    }

    /**
     * Ringkasan siklus penanda untuk satu outlet.
     *
     * Dipisahkan dari daftar slot karena jawabannya bukan daftar: layar setelan
     * memakainya untuk memperingatkan kalau penanda yang sama berulang lebih
     * cepat daripada umur piring terpanjang — masalah yang melahirkan fitur ini.
     *
     * Type-hint Illuminate ditulis lengkap: berkas ini meng-import `Request`
     * milik Symfony di atas, dan `validate()` tidak ada di sana.
     */
    public function timesettingssummary(\Illuminate\Http\Request $request)
    {
        // Dua pemanggil, dua sudut pandang. Layar dapur hanya tahu outletnya;
        // halaman setelan admin bekerja per brand dan tidak punya outlet sama
        // sekali. Salah satu wajib ada — tanpa keduanya, jawabannya hanya bisa
        // berupa tebakan.
        $request->validate([
            'outlet_id' => ['required_without:brand_id', 'uuid'],
            'brand_id'  => ['required_without:outlet_id', 'uuid', 'exists:brands,id'],
        ]);

        $summary = $request->filled('brand_id')
            ? $this->service->timeSlot->markerCycleSummary($request->query('brand_id'))
            : $this->service->timeSlot->summaryForOutlet($request->query('outlet_id'));

        return $this->success($summary);
    }

}
