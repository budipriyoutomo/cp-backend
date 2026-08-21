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
            // `users.id` satu-satunya auto-increment di skema ini, jadi tanpa
            // cast ia terkirim sebagai angka JSON — sementara `/login` sudah
            // lama dibaca frontend sebagai string. Satu user jadi punya dua
            // bentuk id tergantung ia baru login atau baru me-restore sesi.
            // `UserManagementResource` sudah melakukan cast ini sejak awal.
            'id'         => (string) $this->id,
            'name'       => $this->name, 
            'role'       => $this->role,
            'departemen' => $this->departemen,
            'outlet'     => is_array($this->outlet) ? $this->outlet : json_decode($this->outlet, true),
            'module_app' => is_array($this->module_app) ? $this->module_app : json_decode($this->module_app, true),
        ];
    }
}
