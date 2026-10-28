<?php

namespace Tests\Feature;

use Tests\TestCase;

class WebDashboardTest extends TestCase
{
    public function test_dashboard_renders_bengali_task_and_approval_views(): void
    {
        $this->withoutVite()
            ->get('/')
            ->assertOk()
            ->assertSee('আমার কাজ')
            ->assertSee('অনুমোদন')
            ->assertSee('Sanctum API token')
            ->assertSee('mobile-navigation')
            ->assertSee('mobile-more-button')
            ->assertSee('Brain ও model routing')
            ->assertSee('Auto')
            ->assertSee('Fixed')
            ->assertSee('Custom')
            ->assertSee('Sandbox mode');
    }
}
