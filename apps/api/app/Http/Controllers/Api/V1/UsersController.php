<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UsersController extends Controller
{
    /**
     * GET /api/v1/users
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('usuarios.ver');

        $query = User::with('roles')->orderBy('name');

        if ($request->filled('rol')) {
            $query->role($request->rol);
        }

        if ($request->filled('activo')) {
            $query->where('is_active', filter_var($request->activo, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'ilike', "%{$q}%")
                    ->orWhere('email', 'ilike', "%{$q}%")
                    ->orWhere('dni', 'ilike', "%{$q}%");
            });
        }

        return response()->json($query->paginate(20));
    }

    /**
     * POST /api/v1/users
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('usuarios.crear');

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:150'],
            'email'    => ['required', 'email', 'unique:users,email'],
            'dni'      => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8'],
            'rol'      => ['required', 'string', Rule::exists('roles', 'name')],
        ]);

        $user = User::create([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'dni'       => $data['dni'] ?? null,
            'password'  => Hash::make($data['password']),
            'is_active' => true,
        ]);

        $user->assignRole($data['rol']);

        AuditService::log('usuario.creado', $user, [], $user->toArray());

        return response()->json($this->format($user), 201);
    }

    /**
     * GET /api/v1/users/{user}
     */
    public function show(User $user): JsonResponse
    {
        $this->authorize('usuarios.ver');

        return response()->json($this->format($user->load('roles')));
    }

    /**
     * PATCH /api/v1/users/{user}
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorize('usuarios.editar');

        $data = $request->validate([
            'name'  => ['sometimes', 'string', 'max:150'],
            'dni'   => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'rol'   => ['sometimes', 'string', Rule::exists('roles', 'name')],
        ]);

        $old = $user->toArray();

        $user->update(collect($data)->except('rol')->toArray());

        if (isset($data['rol'])) {
            $user->syncRoles([$data['rol']]);
        }

        AuditService::log('usuario.actualizado', $user, $old, $user->fresh()->toArray());

        return response()->json($this->format($user->fresh()->load('roles')));
    }

    /**
     * PATCH /api/v1/users/{user}/desactivar
     */
    public function desactivar(Request $request, User $user): JsonResponse
    {
        $this->authorize('usuarios.desactivar');

        // No se puede desactivar al propio usuario
        if ($user->id === $request->user()->id) {
            return response()->json([
                'message' => 'No puedes desactivar tu propia cuenta.',
                'code'    => 'SELF_DEACTIVATION',
            ], 422);
        }

        $old = $user->toArray();
        $user->update(['is_active' => false]);

        AuditService::log('usuario.desactivado', $user, $old, $user->fresh()->toArray());

        return response()->json(['message' => 'Usuario desactivado correctamente.']);
    }

    /**
     * GET /api/v1/roles
     * Lista roles disponibles para asignar (admin_sistema).
     */
    public function roles(): JsonResponse
    {
        $this->authorize('usuarios.crear');

        return response()->json(Role::orderBy('name')->get(['id', 'name']));
    }

    private function format(User $user): array
    {
        return [
            'id'         => $user->id,
            'name'       => $user->name,
            'email'      => $user->email,
            'dni'        => $user->dni,
            'is_active'  => $user->is_active,
            'roles'      => $user->getRoleNames(),
            'created_at' => $user->created_at,
        ];
    }
}
