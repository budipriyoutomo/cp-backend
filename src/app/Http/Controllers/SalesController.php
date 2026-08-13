<?php 

namespace App\Http\Controllers;

use App\Http\Requests\Sales\StoreSalesRequest;  
use App\Http\Resources\Sales\SalesResource;
use App\Http\Resources\Sales\SalesDraftResource;
use App\Services\Sales\SalesService;
use Illuminate\Http\Request;

/**
 * Envelope is `{ status, message, data }` like every other controller.
 * This one used to return bare JsonResources, i.e. `{ data }` with no status.
 */
class SalesController extends BaseApiController
{
    public function __construct(
        protected SalesService $service
    ) {}

    // ==========================
    // LIST
    // ==========================
    public function index(Request $request)
    {
        return $this->resource(SalesResource::collection(
            $this->service->list($request)
        ));
    }

    // ==========================
    // LIST
    // ==========================
    public function drafts(Request $request)
    {
        return $this->resource(SalesDraftResource::collection(
            $this->service->list($request)
        ));
    }

    // ==========================
    // CREATE (AGGREGATE)
    // ==========================
    public function store(StoreSalesRequest $request)
    {
        $sales = $this->service->create(
            $request->validated()
        );

        return $this->resource(
            new SalesResource($sales->load('items.details', 'items.plateColor')),
            'Sales saved',
            201
        );
    }

    // ==========================
    // UPDATE (SYNC ITEMS)
    // ==========================
    public function update($id, StoreSalesRequest $request)
    {
        $sales = $this->service->update(
            $id,
            $request->validated()
        );

        return $this->resource(
            new SalesResource($sales->load('items.details', 'items.plateColor'))
        );
    }


    // ==========================
    // SHOW DETAIL
    // ==========================
    public function show($id)
    {
        return $this->resource(new SalesResource($this->service->show($id)));
    }

    // ==========================
    // GET BY DATE + OUTLET
    // ==========================
    public function byDate(Request $request)
    {
        $request->validate([
            'outlet_id' => 'required',
            'date' => 'required|date'
        ]);

        $data = $this->service->getByDateOutlet(
            $request->outlet_id,
            $request->date
        );

        return $this->resource(new SalesResource($data));
    }
}