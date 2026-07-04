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

    public function test_admin_sistema_has_all_permissions(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin_sistema');

        $this->assertTrue($user->hasPermissionTo('usuarios.crear'));
        $this->assertTrue($user->hasPermissionTo('convocatorias.crear'));
        $this->assertTrue($user->hasPermissionTo('evaluaciones.calificar'));
        $this->assertTrue($user->hasPermissionTo('auditoria.ver'));
        $this->assertTrue($user->hasPermissionTo('reportes.ver'));
    }

    public function test_comision_can_close_evaluacion_but_not_create_convocatoria(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('comision');

        $this->assertTrue($user->hasPermissionTo('evaluaciones.cerrar'));
        $this->assertTrue($user->hasPermissionTo('resultados.publicar'));
        $this->assertFalse($user->hasPermissionTo('convocatorias.crear'));
        $this->assertFalse($user->hasPermissionTo('usuarios.crear'));
    }

    public function test_auditor_has_readonly_access(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('auditor');

        $this->assertTrue($user->hasPermissionTo('auditoria.ver'));
        $this->assertTrue($user->hasPermissionTo('reportes.ver'));

        // No puede modificar nada
        $this->assertFalse($user->hasPermissionTo('convocatorias.crear'));
        $this->assertFalse($user->hasPermissionTo('evaluaciones.calificar'));
        $this->assertFalse($user->hasPermissionTo('usuarios.crear'));
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

    public function test_six_roles_exist_in_database(): void
    {
        $this->assertDatabaseHas('roles', ['name' => 'postulante']);
        $this->assertDatabaseHas('roles', ['name' => 'evaluador']);
        $this->assertDatabaseHas('roles', ['name' => 'comision']);
        $this->assertDatabaseHas('roles', ['name' => 'admin_convocatoria']);
        $this->assertDatabaseHas('roles', ['name' => 'admin_sistema']);
        $this->assertDatabaseHas('roles', ['name' => 'auditor']);
    }
}
