<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_pages_render(): void
    {
        $this->seed(DemoSeeder::class);
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        foreach (['/admin', '/admin/components', '/admin/components/create', '/admin/components/1/edit',
            '/admin/component-groups', '/admin/incidents', '/admin/incidents/create', '/admin/incidents/1/edit',
            '/admin/maintenances', '/admin/maintenances/create', '/admin/subscribers', '/admin/webhooks',
            '/admin/webhooks/create', '/admin/users', '/admin/manage-settings'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_editors_cannot_reach_admin_only_areas(): void
    {
        $editor = User::factory()->create(['role' => UserRole::Editor]);

        $this->actingAs($editor)->get('/admin/incidents')->assertOk();
        $this->actingAs($editor)->get('/admin/maintenances')->assertOk();

        foreach (['/admin/components', '/admin/users', '/admin/webhooks', '/admin/subscribers', '/admin/manage-settings'] as $url) {
            $this->actingAs($editor)->get($url)->assertForbidden();
        }
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }
}
