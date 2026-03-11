<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'email'      => $this->email,
            'pin'        => $this->pin,
            'role'       => $this->role,
            'departemen' => $this->departemen,
            'outlet'     => is_array($this->outlet) ? $this->outlet : json_decode($this->outlet, true),
            'module_app' => is_array($this->module_app) ? $this->module_app : json_decode($this->module_app, true),
        ];
    }
}
