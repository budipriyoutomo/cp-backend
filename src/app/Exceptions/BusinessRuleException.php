<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when a request is well-formed but violates a business rule
 * (e.g. submitting a closing report before its sales are submitted).
 *
 * Renders as a 4xx JSON response matching BaseApiController's error shape,
 * instead of bubbling up as a generic HTTP 500.
 */
class BusinessRuleException extends RuntimeException
{
    public function __construct(
        string $message,
        protected int $status = 422,
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'status'  => false,
            'message' => $this->getMessage(),
            'errors'  => null,
        ], $this->status);
    }
}
