<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator; 
use Illuminate\Http\Resources\Json\ResourceCollection; 

class BaseApiController extends Controller
{
    /**
     * Success Response
     */
    protected function success(
        $data = null,
        string $message = 'Success',
        int $code = 200
    ): JsonResponse {
        return response()->json([
            'status'  => true,
            'message' => $message,
            'data'    => $data
        ], $code);
    }

    /**
     * Error Response
     */
    protected function error(
        string $message = 'Error',
        int $code = 400,
        $errors = null
    ): JsonResponse {
        return response()->json([
            'status'  => false,
            'message' => $message,
            'errors'  => $errors
        ], $code);
    }

    /**
     * Response untuk single resource atau collection
     */
    protected function resource($resource, string $message = 'Success', int $code = 200)
    {
        // 🔥 Resource Collection (INI YANG DIPAKAI SEKARANG)
        if ($resource instanceof ResourceCollection) {

            $paginator = $resource->resource;

            // ✅ kalau paginate
            if ($paginator instanceof LengthAwarePaginator) {
                return response()->json([
                    'status' => true,
                    'message' => $message,
                    'data' => $resource->collection,
                    'meta' => [
                        'current_page' => $paginator->currentPage(),
                        'per_page'     => $paginator->perPage(),
                        'total'        => $paginator->total(),
                        'last_page'    => $paginator->lastPage(),
                    ]
                ], $code);
            }

            // ❌ kalau bukan paginate
            return response()->json([
                'status' => true,
                'message' => $message,
                'data' => $resource->collection,
            ], $code);
        }

        // 🔥 Single Resource
        if ($resource instanceof JsonResource) {
            return response()->json([
                'status' => true,
                'message' => $message,
                'data' => $resource,
            ], $code);
        }

        // 🔥 fallback
        return $this->success($resource, $message, $code);
    }

    /**
     * Pagination Response
     */
    protected function paginate(LengthAwarePaginator $paginator, string $message = 'Success')
    {
        return $this->success([
            'items'       => $paginator->items(),
            'total'       => $paginator->total(),
            'per_page'    => $paginator->perPage(),
            'current_page'=> $paginator->currentPage(),
            'last_page'   => $paginator->lastPage(),
        ], $message);
    }

    /**
     * Quick validation fail response
     */
    protected function validationError($validator)
    {
        return $this->error(
            'Validation failed',
            422,
            $validator->errors()
        );
    }

    /**
     * Exception handler shortcut
     */
    protected function exception(\Throwable $e, int $code = 500)
    {
        return $this->error(
            $e->getMessage(),
            $code
        );
    }
}
