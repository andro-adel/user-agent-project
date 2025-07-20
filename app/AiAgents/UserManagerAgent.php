<?php

namespace App\AiAgents;

use LarAgent\Agent;
use LarAgent\Attributes\Tool;
use App\Models\User;

class UserManagerAgent extends Agent
{
    public function instructions(): string {
        return "أنت مساعد ذكي لإدارة المستخدمين عبر أوامر CRUD.";
    }

    #[Tool('List all users')]
    public function listUsers(): array {
        return User::select('id','name','email')->get()->toArray();
    }

    #[Tool('Create a new user with name and email')]
    public function createUser(string $name, string $email): string {
        $u = User::create([
            'name'=>$name,'email'=>$email,'password'=>bcrypt('secret'),
            'is_filament_user'=>true
        ]);
        return "Created user ID: {$u->id}";
    }

    #[Tool('Update user by ID')]
    public function updateUser(int $id, ?string $name = null, ?string $email = null): string {
        $u = User::find($id);
        if (!$u) return "User not found";
        if ($name) $u->name = $name;
        if ($email) $u->email = $email;
        $u->save();
        return "User {$id} updated";
    }

    #[Tool('Delete a user by ID')]
    public function deleteUser(int $id): string {
        $u = User::find($id);
        if (!$u) return "User not found";
        $u->delete();
        return "User {$id} deleted";
    }
}
