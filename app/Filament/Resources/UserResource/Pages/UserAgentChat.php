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

    public function send()
    {
        $agent = new UserManagerAgent(config('laragent.providers.default.api_key'));
        $response = $agent->respond($this->input);
        $this->conversation .= "👤 $this->input\n🤖 $response\n\n";
        $this->input = '';
    }
}
