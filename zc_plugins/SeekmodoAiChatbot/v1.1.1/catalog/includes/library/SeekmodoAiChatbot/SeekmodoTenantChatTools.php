<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot;

/**
 * Extra tools a tenant registered in Seekmodo admin. The gateway
 * owns the server URL and credential. This class only forwards the
 * tool id and the arguments the model chose.
 */
final class SeekmodoTenantChatTools
{
    /** @var list<array{name: string, id: string, description: string, fields: list<array{name: string, type: string}>}> */
    private array $tools = [];

    private bool $loaded = false;

    public function __construct(private SeekmodoProductSearch $search)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        $this->load();
        $out = [];
        foreach ($this->tools as $tool) {
            $properties = [];
            foreach ($tool['fields'] as $field) {
                $properties[$field['name']] = ['type' => $field['type']];
            }
            $out[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'] !== ''
                        ? $tool['description']
                        : 'Store tool ' . $tool['id'],
                    'parameters' => [
                        'type' => 'object',
                        'properties' => $properties === [] ? new \stdClass() : $properties,
                    ],
                ],
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function call(string $name, array $arguments): array
    {
        $this->load();
        foreach ($this->tools as $tool) {
            if ($tool['name'] !== $name) {
                continue;
            }
            $resp = $this->search->gatewayPost('/v1/chat.mcp_call', [
                'tool' => $tool['id'],
                'args' => $arguments,
            ]);
            if (!is_array($resp)) {
                return ['ok' => false, 'text' => 'The tool did not respond.'];
            }
            return [
                'ok' => !empty($resp['ok']),
                'text' => is_string($resp['text'] ?? null) ? $resp['text'] : '',
            ];
        }
        return ['ok' => false, 'text' => 'That tool is not available.'];
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        $cached = $this->readCache();
        if (is_array($cached)) {
            $this->tools = $cached;
            return;
        }
        $resp = $this->search->gatewayPost('/v1/chat.mcp_catalog', []);
        $rows = is_array($resp['tools'] ?? null) ? $resp['tools'] : [];
        $used = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null)) {
                continue;
            }
            $name = self::functionName((string) $row['id'], $used);
            if ($name === '') {
                continue;
            }
            $used[$name] = true;
            $fields = [];
            if (is_array($row['fields'] ?? null)) {
                foreach ($row['fields'] as $field) {
                    if (!is_array($field) || !is_string($field['name'] ?? null)) {
                        continue;
                    }
                    $type = is_string($field['type'] ?? null) ? $field['type'] : 'string';
                    if (!in_array($type, ['string', 'number', 'integer', 'boolean'], true)) {
                        $type = 'string';
                    }
                    $fields[] = ['name' => $field['name'], 'type' => $type];
                }
            }
            $description = is_string($row['description'] ?? null) ? $row['description'] : '';
            $this->tools[] = [
                'name' => $name,
                'id' => (string) $row['id'],
                'description' => $description,
                'fields' => $fields,
            ];
        }
        $this->writeCache($this->tools);
    }

    /**
     * @return list<array{name: string, id: string, description: string, fields: list<array{name: string, type: string}>}>|null
     */
    private function readCache(): ?array
    {
        $path = $this->cachePath();
        if (!is_file($path) || filemtime($path) < time() - 60) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param list<array{name: string, id: string, description: string, fields: list<array{name: string, type: string}>}> $tools
     */
    private function writeCache(array $tools): void
    {
        $encoded = json_encode($tools);
        if (!is_string($encoded)) {
            return;
        }
        @file_put_contents($this->cachePath(), $encoded, LOCK_EX);
    }

    private function cachePath(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'seekmodo-chat-mcp-' . substr(hash('sha256', __DIR__), 0, 16) . '.json';
    }

    /**
     * @param array<string, bool> $used
     */
    private static function functionName(string $id, array $used): string
    {
        $flat = str_replace('::', '_', $id);
        $flat = preg_replace('/[^A-Za-z0-9_]/', '_', $flat) ?? '';
        $name = substr('seekmodo_x_' . $flat, 0, 64);
        if ($name === 'seekmodo_x_' || isset($used[$name])) {
            return '';
        }
        return $name;
    }
}
