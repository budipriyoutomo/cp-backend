<?php 

namespace App\Http\Controllers;

use App\Http\Requests\Sales\StoreSalesRequest;  
use App\Http\Resources\Sales\SalesResource;
use App\Services\Sales\SalesService;
use Illuminate\Http\Request;

class SalesController extends Controller
{
    public function __construct(
        protected SalesService $service
    ) {}

    // ==========================
    // LIST
    // ==========================
    public function index(Request $request)
    {
        return SalesResource::collection(
            $this->service->list($request)
        );
    }

    // ==========================
    // LIST
    // ==========================
    public function drafts(Request $request)
    {
        return SalesResource::collection(
            $this->service->list($request)
        );
    }

    // ==========================
    // CREATE (AGGREGATE)
    // ==========================
    public function store(StoreSalesRequest $request)
    {
        $sales = $this->service->create(
            $request->validated()
        );

        return (new SalesResource(
            $sales->load('items.details','items.plateColor')
        ))->response()->setStatusCode(201);
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

        return new SalesResource(
            $sales->load('items.details','items.plateColor')
        );
    }


    // ==========================
    // SHOW DETAIL
    // ==========================
    public function show($id)
    {
       return new SalesResource(
            $this->service->show($id)
        );
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

        return new SalesResource($data);
    }
}