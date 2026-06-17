<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Resources\UserManagementResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends BaseApiController
{
    /**
     * List users, with optional role / outlet filters.
     */
    public function index(Request $request)
    {
        $query = User::query();

        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        if ($outletId = $request->query('outletId')) {
            $query->whereJsonContains('outlet', $outletId);
        }

        $users = $query->orderBy('name')->get();

        return $this->resource(UserManagementResource::collection($users));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'email'      => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'   => ['required', 'string', 'min:6'],
            'role'       => ['required', 'string', 'max:50'],
            'pin'        => ['nullable', 'string', 'min:4', 'max:10', 'unique:users,pin'],
            'departemen' => ['nullable', 'string', 'max:255'],
            'outlet'     => ['nullable', 'array'],
            'module_app' => ['nullable', 'array'],
        ]);

        $data['password'] = Hash::make($data['password']);

        $user = User::create($data);

        return $this->resource(
            new UserManagementResource($user),
            'User created',
            201
        );
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $data = $request->validate([
            'name'       => ['sometimes', 'string', 'max:255'],
            'email'      => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password'   => ['nullable', 'string', 'min:6'],
            'role'       => ['sometimes', 'string', 'max:50'],
            'pin'        => ['nullable', 'string', 'min:4', 'max:10', Rule::unique('users', 'pin')->ignore($user->id)],
            'departemen' => ['nullable', 'string', 'max:255'],
            'outlet'     => ['nullable', 'array'],
            'module_app' => ['nullable', 'array'],
        ]);

        // Only update the password when a new one is supplied.
        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        return $this->resource(
            new UserManagementResource($user),
            'User updated'
        );
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);

        if ((string) $user->id === (string) auth()->id()) {
            throw new BusinessRuleException('You cannot delete your own account.', 409);
        }

        $user->delete();

        return $this->success(null, 'User deleted');
    }
}
