<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Resources\UserManagementResource;
use App\Models\User;
use App\Support\AccessOptions;
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
            'role'       => ['required', 'string', Rule::in(AccessOptions::ROLES)],
            'pin'        => ['nullable', 'string', 'min:6', 'max:10', $this->uniquePinRule()],
            'departemen' => ['nullable', 'string', 'max:255'],
            'outlet'     => ['nullable', 'array'],
            'outlet.*'   => ['string', 'max:50'],
            'module_app'   => ['nullable', 'array'],
            'module_app.*' => ['string', Rule::in(AccessOptions::MODULE_APPS)],
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
            'role'       => ['sometimes', 'string', Rule::in(AccessOptions::ROLES)],
            'pin'        => ['nullable', 'string', 'min:6', 'max:10', $this->uniquePinRule($user->id)],
            'departemen' => ['nullable', 'string', 'max:255'],
            'outlet'     => ['nullable', 'array'],
            'outlet.*'   => ['string', 'max:50'],
            'module_app'   => ['nullable', 'array'],
            'module_app.*' => ['string', Rule::in(AccessOptions::MODULE_APPS)],
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

    /**
     * PIN uniqueness is checked against the blind index: users.pin holds a
     * bcrypt hash, so `unique:users,pin` could never match anything.
     */
    private function uniquePinRule(?int $ignoreUserId = null): callable
    {
        return function (string $attribute, $value, callable $fail) use ($ignoreUserId) {
            if ($value === null || $value === '') {
                return;
            }

            $taken = User::where('pin_lookup', User::pinLookup((string) $value))
                ->when($ignoreUserId, fn ($q) => $q->where('id', '!=', $ignoreUserId))
                ->exists();

            if ($taken) {
                $fail('The pin has already been taken.');
            }
        };
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
