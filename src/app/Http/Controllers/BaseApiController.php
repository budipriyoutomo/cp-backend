<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

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
    protected function resource(JsonResource $resource, string $message = 'Success', int $code = 200)
    {
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
