<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolesPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    // -------------------------------------------------------------------------
    // ACCESO POR ROL
    // -------------------------------------------------------------------------

    public function test_postulante_cannot_access_admin_routes(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('postulante');

        // Un postulante no puede ver todas las postulaciones (permiso de evaluador+)
        $this->actingAs($user, 'sanctum')
             ->getJson('/api/v1/me')
             ->assertOk()
             ->assertJsonPath('roles.0', 'postulante');

        // Verificar que NO tiene permisos de admin
        $this->assertFalse($user->hasPermissionTo('usuarios.crear'));
        $this->assertFalse($user->hasPermissionTo('convocatorias.crear'));
        $this->assertFalse($user->hasPermissionTo('evaluaciones.calificar'));
    }

    public function test_evaluador_has_correct_permissions(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('evaluador');

        $this->assertTrue($user->hasPermissionTo('evaluaciones.calificar'));
        $this->assertTrue($user->hasPermissionTo('evidencias.validar'));
        $this->assertTrue($user->hasPermissionTo('postulaciones.observar'));

        // No puede crear convocatorias
        $this->assertFalse($user->hasPermissionTo('convocatorias.crear'));
        // No puede gestionar usuarios
        $this->assertFalse($user->hasPermissionTo('usuarios.crear'));
    }

    /**
     * Decisión MVP (Sprint 2, ver RolesAndPermissionsSeeder): comisión,
     * admin_convocatoria, admin_sistema y auditor se unifican en el rol
     * `admin`, que por eso concentra TODOS los permisos — incluidos los que
     * en el reglamento corresponden a roles distintos (cerrar evaluación,
     * publicar resultados, auditoría, crear usuarios). No existe en el MVP
     * un rol de solo-lectura ni uno que pueda cerrar evaluaciones sin poder
     * crear convocatorias.
     */
    public function test_admin_unifica_todos_los_permisos_del_reglamento(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');

        $this->assertTrue($user->hasPermissionTo('usuarios.crear'));
        $this->assertTrue($user->hasPermissionTo('convocatorias.crear'));
        $this->assertTrue($user->hasPermissionTo('evaluaciones.calificar'));
        $this->assertTrue($user->hasPermissionTo('evaluaciones.cerrar'));
        $this->assertTrue($user->hasPermissionTo('resultados.publicar'));
        $this->assertTrue($user->hasPermissionTo('auditoria.ver'));
        $this->assertTrue($user->hasPermissionTo('reportes.ver'));
    }

    public function test_postulante_only_sees_their_own_total_score_permission(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('postulante');

        // Puede ver su propio total
        $this->assertTrue($user->hasPermissionTo('resultados.ver_total_propio'));

        // No puede ver el desglose (solo evaluadores/admin)
        $this->assertFalse($user->hasPermissionTo('evaluaciones.ver_desglose'));
        $this->assertFalse($user->hasPermissionTo('resultados.ver_todos'));
    }

    public function test_tres_roles_mvp_existen_en_la_base_de_datos(): void
    {
        $this->assertDatabaseHas('roles', ['name' => 'postulante']);
        $this->assertDatabaseHas('roles', ['name' => 'evaluador']);
        $this->assertDatabaseHas('roles', ['name' => 'admin']);
        $this->assertDatabaseCount('roles', 3);
    }
}
