<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\Page;
use App\AiAgents\UserManagerAgent;

class UserAgentChat extends Page
{
    protected static string $resource = UserResource::class;

    protected static string $view = 'filament.resources.user-resource.pages.user-agent-chat';

    public string $input = '', $conversation = '';

    public function mount()
    {
        // Load conversation from session or initialize it
        $this->conversation = session('user_agent_conversation', '');
    }

    public function send()
    {
        if (empty($this->input)) {
            return;
        }

        $this->conversation .= "👤 " . $this->input . "\n";

        $agent = new UserManagerAgent('user_agent_chat_session');
        $result = $agent->processMessage(['text' => $this->input]);

        // Convert array/object result to a readable string for the chat
        $response = is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $this->conversation .= "🤖 " . $response . "\n\n";

        // Save conversation to session
        session(['user_agent_conversation' => $this->conversation]);

        $this->input = '';
    }
}
