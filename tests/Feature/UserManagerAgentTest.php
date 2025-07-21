<?php

namespace Tests\Feature;

use App\AiAgents\UserManagerAgent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagerAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_agent_can_create_user_from_natural_language_command()
    {
        $this->assertNotEmpty(config('laragent.providers.default.api_key'), 'The OpenAI API key must be set in config.');
        $agent = new UserManagerAgent('test-session');
        $command = 'اضف مستخدم اسمه كريم وبريده test@example.com وباسورد Pass123!';
        $result = $agent->processMessage(['text' => $command]);
        dump($result);

        $this->assertArrayHasKey('id', $result);
        $this->assertDatabaseHas('users', [
            'name' => 'كريم',
            'email' => 'test@example.com',
        ]);
    }
}
