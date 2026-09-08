<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UiImprovementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_titles_use_goalz_without_losing_the_page_title(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('goals.index'));

        $this->assertSame('Goalz', config('app.name'));
        $response->assertOk()
            ->assertSeeText('Goals - Goalz')
            ->assertDontSeeText('Goals - Laravel')
            ->assertDontSeeText('Laravel');
    }
}
