<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardLazyLoadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_page_shows_a_loading_placeholder_before_its_figures(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Loading dashboard…')
            ->assertSee('<h2 class="font-semibold text-xl text-gray-800 dark:text-gray-100 leading-tight">Dashboard</h2>', false)
            ->assertDontSee('No DMARC reports have been collected yet.');
    }
}
