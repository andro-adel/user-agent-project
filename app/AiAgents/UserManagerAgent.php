<?php

namespace App\AiAgents;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use LarAgent\Agent;
use LarAgent\Attributes\Tool;
use ReflectionMethod;

class UserManagerAgent extends Agent
{
    protected $model   = 'gpt-4';
    protected $history = 'in_memory';

    public function instructions(): string
    {
        return <<<'TXT'
أنت مساعد ذكي لإدارة المستخدمين. افهم أوامر CRUD المعقدة بالعربية والإنجليزية.
يجب عليك دائمًا إرجاع استدعاء أداة بصيغة JSON فقط.

أمثلة على الأوامر والاستجابات المتوقعة:
- "Create user named Bob, email bob@example.com, password Secret!"
  {"tool":"createUser","args":{"name":"Bob","email":"bob@example.com","password":"Secret"}}
- "Show users page 2, 10 per page"
  {"tool":"listUsers","args":{"page":2,"perPage":10}}
- "اعرض لي الصفحة الأخيرة من المستخدمين"
  {"tool":"listUsers","args":{"page":"last","perPage":10}}
- "Show me the last 3 users"
  {"tool":"listLastUsers","args":{"count":3}}
- "Add 2 users: 1. name Ali email a@a.com pass 123. 2. name Sara email s@s.com pass 456"
  {"tool":"batchCreate","args":{"usersJson":"[{\"name\":\"Ali\",\"email\":\"a@a.com\",\"password\":\"123\"},{\"name\":\"Sara\",\"email\":\"s@s.com\",\"password\":\"456\"}]"}}
- "Update user id 5, set name to Ahmed"
  {"tool":"updateUser","args":{"id":5,"name":"Ahmed"}}
- "Delete user id 10"
  {"tool":"deleteUser","args":{"id":10}}

مهم: لا تقم أبداً بإرجاع أي نص آخر غير JSON الخاص باستدعاء الأداة.
TXT;
    }

    #[Tool('List users with pagination. Set page to "last" to get the last page.')]
    public function listUsers($page = 1, int $perPage = 10): array
    {
        $perPage = min($perPage, 100);

        if ($page === 'last') {
            $total = User::count();
            $lastPage = ceil($total / $perPage);
            $page = $lastPage > 0 ? (int)$lastPage : 1;
        }

        $paginator = User::select('id', 'name', 'email', 'created_at')
            ->paginate($perPage, ['*'], 'page', (int)$page);

        return [
            'current_page' => $paginator->currentPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
            'last_page'    => $paginator->lastPage(),
            'data'         => $paginator->items(),
        ];
    }

    #[Tool('List the most recently created users by creation date')]
    public function listLastUsers(int $count = 5): array
    {
        return User::select('id', 'name', 'email', 'created_at')
            ->orderBy('created_at', 'desc')
            ->limit($count)
            ->get()
            ->toArray();
    }

    #[Tool('Create new user')]
    public function createUser(string $name, string $email, string $password): array
    {
        $u = User::create([
            'name'             => $name,
            'email'            => $email,
            'password'         => Hash::make($password),
        ]);
        return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email];
    }

    #[Tool('Batch create users - pass users as a JSON string')]
    public function batchCreate(string $usersJson): array
    {
        $users = json_decode($usersJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'Invalid JSON format: ' . json_last_error_msg()];
        }

        if (count($users) > 50) {
            return ['error' => 'Maximum batch size is 50 users'];
        }

        $results = [];
        foreach ($users as $i => $info) {
            if (empty($info['name']) || empty($info['email']) || empty($info['password'])) {
                $results[] = ['index' => $i, 'id' => null, 'error' => 'Missing name, email, or password.'];
                continue;
            }
            $u = User::create([
                'name'     => $info['name'],
                'email'    => $info['email'],
                'password' => Hash::make($info['password']),
            ]);
            $results[] = ['index' => $i, 'id' => $u->id];
        }
        return ['count' => count($results), 'results' => $results];
    }

    #[Tool('Update user')]
    public function updateUser(int $id, ?string $name = null, ?string $email = null, ?string $password = null): array
    {
        $u = User::find($id);
        if (!$u) {
            return ['error' => 'User not found'];
        }
        if ($name) {
            $u->name = $name;
        }
        if ($email) {
            $u->email = $email;
        }
        if ($password) {
            $u->password = Hash::make($password);
        }
        $u->save();
        return ['id' => $id, 'status' => 'updated'];
    }

    #[Tool('Delete user')]
    public function deleteUser(int $id): array
    {
        $deleted = User::find($id)?->delete();
        return ['id' => $id, 'deleted' => (bool)$deleted];
    }

    public function processMessage(array $msg): mixed
    {
        // If the message is a direct tool call, execute it
        if (isset($msg['tool'])) {
            $tool = $msg['tool'];
            $args = $msg['args'] ?? [];

            if (!method_exists($this, $tool)) {
                return ['error' => "Unknown tool: $tool"];
            }

            $ref = new ReflectionMethod($this, $tool);
            $params = $ref->getParameters();
            $orderedArgs = [];
            foreach ($params as $param) {
                $name = $param->getName();
                $orderedArgs[] = $args[$name] ?? ($param->isDefaultValueAvailable() ? $param->getDefaultValue() : null);
            }
            return $ref->invokeArgs($this, $orderedArgs);
        }

        // If the message is natural language text, ask the LLM to convert it to a tool call
        if (!empty($msg['text'])) {
            $llmResponseAsJson = $this->respond($msg['text']);
            $decoded = json_decode($llmResponseAsJson, true);

            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['tool'])) {
                // LLM returned a valid tool call, process it recursively
                return $this->processMessage($decoded);
            }

            // If the LLM response is not a valid JSON tool call, return it as a string
            return $llmResponseAsJson;
        }

        return ['message' => 'Sorry, I could not understand your request. Please provide a command.'];
    }
}
