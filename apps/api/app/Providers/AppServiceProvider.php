<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Permission;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Registrar todos los permisos de Spatie como Gates de Laravel
        // Esto permite usar $this->authorize('permiso.nombre') en los controllers
        Gate::before(function ($user, $ability) {
            if ($user->hasRole('admin')) {
                return true; // admin pasa todo sin revisar
            }
        });

        try {
            Permission::all()->each(function ($permission) {
                Gate::define($permission->name, function ($user) use ($permission) {
                    return $user->hasPermissionTo($permission);
                });
            });
        } catch (\Exception) {
            // La tabla permissions puede no existir en el primer migrate
        }
    }
}
