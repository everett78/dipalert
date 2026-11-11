<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KeyPagesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        User::query()->delete();

        $this->user = User::factory()->create([
            'name' => 'Tester',
            'email' => 'tester@test.com',
            'password' => Hash::make('password'),
        ]);
    }

    public function test_homepage_redirects()
    {
        $this->get('/')
            ->assertRedirect(route('filament.admin.pages.home-dashboard'));
    }

    public function test_admin_requires_login()
    {
        $this->get(route('filament.admin.pages.home-dashboard'))
            ->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_can_view_dash_when_auth()
    {
        $this->actingAs($this->user);

        $this->get(route('filament.admin.pages.home-dashboard'))
            ->assertOk();
    }
}
