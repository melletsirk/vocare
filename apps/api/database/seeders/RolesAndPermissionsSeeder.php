<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Limpiar caché y datos anteriores
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Eliminar roles y permisos viejos para empezar limpio
        \DB::table('role_has_permissions')->delete();
        \DB::table('model_has_roles')->delete();
        \DB::table('model_has_permissions')->delete();
        Role::query()->delete();
        Permission::query()->delete();

        // --- Permisos ---
        $permisos = [
            // Convocatorias
            'convocatorias.ver', 'convocatorias.crear',
            'convocatorias.editar', 'convocatorias.cerrar',
            // Plazas
            'plazas.ver', 'plazas.crear', 'plazas.editar',
            // Postulaciones
            'postulaciones.ver_propias', 'postulaciones.crear',
            'postulaciones.enviar', 'postulaciones.ver_todas',
            'postulaciones.observar', 'postulaciones.rechazar',
            // Evidencias
            'evidencias.subir', 'evidencias.ver_propias',
            'evidencias.ver_todas', 'evidencias.validar',
            // Evaluaciones
            'evaluaciones.ver', 'evaluaciones.calificar',
            'evaluaciones.cerrar', 'evaluaciones.ver_desglose',
            // Asignación de evaluadores
            'asignaciones.gestionar', 'asignaciones.ver',
            // CRUD admin de tablas de evaluación (Anexos) y Etapa
            'tablas_evaluacion.gestionar',
            // Resultados
            'resultados.ver_total_propio', 'resultados.ver_todos',
            'resultados.publicar',
            // Usuarios y auditoría (solo admin)
            'usuarios.ver', 'usuarios.crear',
            'usuarios.editar', 'usuarios.desactivar',
            'auditoria.ver', 'reportes.ver',
        ];

        foreach ($permisos as $p) {
            Permission::create(['name' => $p, 'guard_name' => 'web']);
        }

        // ── Rol 1: postulante ────────────────────────────────────────────────
        $postulante = Role::create(['name' => 'postulante', 'guard_name' => 'web']);
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

        // ── Rol 2: evaluador ────────────────────────────────────────────────
        // Valida evidencias, califica, cierra evaluaciones, ve resultados y rankings
        $evaluador = Role::create(['name' => 'evaluador', 'guard_name' => 'web']);
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
            'evaluaciones.cerrar',
            'evaluaciones.ver_desglose',
            'asignaciones.ver',
            'resultados.ver_todos',
            'resultados.publicar',
            'reportes.ver',
        ]);

        // ── Rol 3: admin ─────────────────────────────────────────────────────
        // Control total: usuarios, convocatorias, resultados, auditoría
        $admin = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions(Permission::all());

        $this->command->info('✅ Roles creados: postulante | evaluador | admin');
        $this->command->table(
            ['Rol', 'N° Permisos'],
            Role::with('permissions')->get()->map(fn($r) => [
                $r->name,
                $r->permissions->count(),
            ])
        );
    }
}
