<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserManagementResource extends JsonResource
{
    /**
     * Shape a user for the admin user-management screen.
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => (string) $this->id,
            'name'       => $this->name,
            'email'      => $this->email,
            'role'       => $this->role,
            'departemen' => $this->departemen,
            'outlet'     => is_array($this->outlet) ? $this->outlet : (json_decode($this->outlet ?? 'null', true)),
            'module_app' => is_array($this->module_app) ? $this->module_app : (json_decode($this->module_app ?? 'null', true)),
            'hasPin'     => !empty($this->pin),
            'createdAt'  => $this->created_at?->toDateTimeString(),
        ];
    }
}
