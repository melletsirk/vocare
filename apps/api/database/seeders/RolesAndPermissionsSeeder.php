<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Limpiar caché de permisos
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // --- Permisos ---
        $permissions = [
            // Convocatorias
            'convocatorias.ver',
            'convocatorias.crear',
            'convocatorias.editar',
            'convocatorias.cerrar',

            // Plazas
            'plazas.ver',
            'plazas.crear',
            'plazas.editar',

            // Postulaciones
            'postulaciones.ver_propias',
            'postulaciones.crear',
            'postulaciones.enviar',
            'postulaciones.ver_todas',
            'postulaciones.observar',
            'postulaciones.rechazar',

            // Evidencias
            'evidencias.subir',
            'evidencias.ver_propias',
            'evidencias.ver_todas',
            'evidencias.validar',

            // Evaluaciones
            'evaluaciones.ver',
            'evaluaciones.calificar',
            'evaluaciones.cerrar',
            'evaluaciones.ver_desglose',

            // Resultados
            'resultados.ver_total_propio',  // postulante solo ve total
            'resultados.ver_todos',
            'resultados.publicar',

            // Usuarios
            'usuarios.ver',
            'usuarios.crear',
            'usuarios.editar',
            'usuarios.desactivar',

            // Auditoría
            'auditoria.ver',

            // Reportes
            'reportes.ver',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // --- Roles y sus permisos ---

        // 1. Postulante: solo ve sus propias cosas y puede postular/subir evidencias
        $postulante = Role::firstOrCreate(['name' => 'postulante', 'guard_name' => 'web']);
        $postulante->syncPermissions([
            'convocatorias.ver',
            'plazas.ver',
            'postulaciones.ver_propias',
            'postulaciones.crear',
            'postulaciones.enviar',
            'evidencias.subir',
            'evidencias.ver_propias',
            'resultados.ver_total_propio',
        ]);

        // 2. Evaluador: valida evidencias y califica expedientes asignados
        $evaluador = Role::firstOrCreate(['name' => 'evaluador', 'guard_name' => 'web']);
        $evaluador->syncPermissions([
            'convocatorias.ver',
            'plazas.ver',
            'postulaciones.ver_todas',
            'postulaciones.observar',
            'postulaciones.rechazar',
            'evidencias.ver_todas',
            'evidencias.validar',
            'evaluaciones.ver',
            'evaluaciones.calificar',
            'evaluaciones.ver_desglose',
            'resultados.ver_todos',
        ]);

        // 3. Comisión: decide empates, visualiza comparativos, cierra evaluaciones
        $comision = Role::firstOrCreate(['name' => 'comision', 'guard_name' => 'web']);
        $comision->syncPermissions([
            'convocatorias.ver',
            'plazas.ver',
            'postulaciones.ver_todas',
            'evidencias.ver_todas',
            'evaluaciones.ver',
            'evaluaciones.ver_desglose',
            'evaluaciones.cerrar',
            'resultados.ver_todos',
            'resultados.publicar',
        ]);

        // 4. Admin Convocatoria: crea/gestiona convocatorias, plazas y asigna evaluadores
        $adminConvocatoria = Role::firstOrCreate(['name' => 'admin_convocatoria', 'guard_name' => 'web']);
        $adminConvocatoria->syncPermissions([
            'convocatorias.ver',
            'convocatorias.crear',
            'convocatorias.editar',
            'convocatorias.cerrar',
            'plazas.ver',
            'plazas.crear',
            'plazas.editar',
            'postulaciones.ver_todas',
            'postulaciones.observar',
            'postulaciones.rechazar',
            'evidencias.ver_todas',
            'evidencias.validar',
            'evaluaciones.ver',
            'evaluaciones.ver_desglose',
            'evaluaciones.cerrar',
            'resultados.ver_todos',
            'resultados.publicar',
            'reportes.ver',
        ]);

        // 5. Admin Sistema: acceso total, gestión de usuarios
        $adminSistema = Role::firstOrCreate(['name' => 'admin_sistema', 'guard_name' => 'web']);
        $adminSistema->syncPermissions(Permission::all());

        // 6. Auditor: solo lectura en auditoría y reportes
        $auditor = Role::firstOrCreate(['name' => 'auditor', 'guard_name' => 'web']);
        $auditor->syncPermissions([
            'convocatorias.ver',
            'plazas.ver',
            'postulaciones.ver_todas',
            'evaluaciones.ver',
            'evaluaciones.ver_desglose',
            'resultados.ver_todos',
            'auditoria.ver',
            'reportes.ver',
        ]);

        $this->command->info('✅ Roles y permisos creados correctamente.');
        $this->command->table(
            ['Rol', 'Permisos'],
            Role::with('permissions')->get()->map(fn($r) => [
                $r->name,
                $r->permissions->pluck('name')->join(', ')
            ])
        );
    }
}
