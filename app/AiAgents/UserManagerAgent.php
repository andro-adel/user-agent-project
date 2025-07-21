<?php

namespace App\AiAgents;

use LarAgent\Agent;
use LarAgent\Attributes\Tool;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use ReflectionMethod;

class UserManagerAgent extends Agent
{
    protected $model   = 'gpt-4';
    protected $history = 'in_memory';

    public function instructions(): string
    {
        return <<<'TXT'
أنت مساعد ذكي لإدارة المستخدمين. افهم نصوص CRUD (عربي/إنجليزي) مثل:
- "اضف مستخدم اسمه كريم وبريده a@b.com وباسورد Pass123!"
- "Create user named Bob email bob@example.com password Secret!"
- "Delete user id 5"
- "أظهر المستخدمين صفحة 2 عدد 10"
أرجع JSON استدعاء الأداة:
{"tool":"createUser","args":{...}}
ثم نفّذه وارجع النتيجة.
TXT;
    }

    #[Tool('List users with pagination')]
    public function listUsers(int $page = 1, int $perPage = 10): array
    {
        // Cap perPage to prevent excessive data
        $perPage = min($perPage, 100);

        $paginator = User::select('id', 'name', 'email')
            ->paginate($perPage, ['*'], 'page', $page);

        // Return only essential data
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'data' => $paginator->items(),
        ];
    }

    #[Tool('Create new user')]
    public function createUser(string $name, string $email, string $password): array
    {
        $u = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'is_filament_user' => true,
        ]);
        return ['id' => $u->id, 'initial_password' => $password];
    }

    #[Tool('Batch create users - pass users as JSON string')]
    public function batchCreate(string $usersJson): array
    {
        $users = json_decode($usersJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'Invalid JSON: ' . json_last_error_msg()];
        }

        // Limit batch size
        if (count($users) > 50) {
            return ['error' => 'Maximum batch size is 50 users'];
        }

        $results = [];
        foreach ($users as $i => $info) {
            $u = User::create([
                'name' => $info['name'],
                'email' => $info['email'],
                'password' => Hash::make($info['password']),
                'is_filament_user' => true,
            ]);
            $results[] = ['index' => $i, 'id' => $u->id];
        }
        return ['count' => count($results), 'results' => $results];
    }

    #[Tool('Update user')]
    public function updateUser(int $id, ?string $name = null, ?string $email = null, ?string $password = null): array
    {
        $u = User::find($id);
        if (!$u) return ['error' => 'not found'];
        if ($name) $u->name = $name;
        if ($email) $u->email = $email;
        if ($password) $u->password = Hash::make($password);
        $u->save();
        return ['id' => $id];
    }

    #[Tool('Delete user')]
    public function deleteUser(int $id): array
    {
        User::find($id)?->delete();
        return ['id' => $id];
    }

    protected function parseCommand(string $text): ?array
    {
        // Normalize text by detaching Arabic 'and' (و) from keywords
        $text = ' ' . str_replace('!', ' !', $text) . ' ';
        $keywords = ['بريده', 'باسورد', 'اسمه', 'كلمة', 'ايميله', 'البريد', 'name', 'email', 'password', 'id'];
        $prefixed_keywords = array_map(fn($k) => ' و' . $k, $keywords);
        $spaced_keywords = array_map(fn($k) => ' ' . $k, $keywords);
        $text = str_replace($prefixed_keywords, $spaced_keywords, $text);

        // List users with pagination (improved regex)
        if (preg_match('/\b(?:أظهر|عرض|show|list)\b/iu', $text) && preg_match('/\b(?:page\s*)?(\d+)/iu', $text, $pageMatch)) {
            $perPage = 10;
            if (preg_match('/\b(?:عدد|per[ _]?page\s*)?(\d+)/iu', $text, $perPageMatch)) {
                $perPage = (int)$perPageMatch[1];
            }
            return ['tool' => 'listUsers', 'args' => [
                'page' => (int)$pageMatch[1],
                'perPage' => $perPage
            ]];
        }

        // Create user (supports any parameter order)
        if (preg_match('/\b(اضف|أنشئ|add|create)\b/iu', $text) && preg_match('/\b(مستخدم|user)\b/iu', $text)) {
            $args = [];
            if (preg_match('/(?:name|اسم(?:ه)?)\s+([^\s]+)/iu', $text, $m)) $args['name'] = $m[1];
            if (preg_match('/(?:email|بريد(?:ه)?|البريد|الإيميل|ايميله)\s+([^\s]+)/iu', $text, $m)) $args['email'] = $m[1];
            if (preg_match('/(?:password|كلمة[_\s]?(?:المرور|السر)|باسوورد|باسورد)\s+([^\s]+)/iu', $text, $m)) $args['password'] = $m[1];
            if (count($args) === 3) {
                return ['tool' => 'createUser', 'args' => $args];
            }
        }

        // Update user (supports any parameters)
        if (preg_match('/\b(تحديث|update)\b/iu', $text) && preg_match('/\b(مستخدم|user)\b/iu', $text)) {
            $args = [];
            if (preg_match('/id\s+(\d+)/iu', $text, $m)) $args['id'] = (int)$m[1];
            if (preg_match('/(?:name|اسم(?:ه)?)\s+([^\s]+)/iu', $text, $m)) $args['name'] = $m[1];
            if (preg_match('/(?:email|بريد(?:ه)?|البريد|الإيميل|ايميله)\s+([^\s]+)/iu', $text, $m)) $args['email'] = $m[1];
            if (preg_match('/(?:password|كلمة[_\s]?(?:المرور|السر)|باسوورد|باسورد)\s+([^\s]+)/iu', $text, $m)) $args['password'] = $m[1];
            if (isset($args['id']) && count($args) > 1) {
                return ['tool' => 'updateUser', 'args' => $args];
            }
        }

        // Delete user
        if (preg_match('/\b(حذف|delete)\b/iu', $text) && preg_match('/\b(مستخدم|user)\b/iu', $text)) {
            if (preg_match('/id\s+(\d+)/iu', $text, $m)) {
                return ['tool' => 'deleteUser', 'args' => ['id' => (int)$m[1]]];
            }
        }

        return null;
    }

    public function processMessage(array $msg): mixed
    {
        if (isset($msg['tool'])) {
            $tool = $msg['tool'];
            $args = $msg['args'] ?? [];

            if (!method_exists($this, $tool)) {
                return ['error' => "Unknown tool: $tool"];
            }

            // Map named parameters using reflection
            $ref = new ReflectionMethod($this, $tool);
            $params = $ref->getParameters();

            $orderedArgs = [];
            foreach ($params as $param) {
                $name = $param->getName();
                $orderedArgs[] = $args[$name] ?? ($param->isDefaultValueAvailable()
                    ? $param->getDefaultValue()
                    : null);
            }

            return $ref->invokeArgs($this, $orderedArgs);
        }

        if (!empty($msg['text'])) {
            $cmd = $this->parseCommand($msg['text']);
            if ($cmd) return $this->processMessage($cmd);
        }

        return ['message' => 'عذراً، لم أفهم طلبك. الرجاء استخدام نص CRUD.'];
    }
}
