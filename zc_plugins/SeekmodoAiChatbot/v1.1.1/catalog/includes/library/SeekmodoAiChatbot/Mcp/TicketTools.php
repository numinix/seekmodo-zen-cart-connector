<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot\Mcp;

use Numinix\Mcp\Tool;
use Numinix\Mcp\ToolContext;
use Numinix\Mcp\ToolException;
use SeekmodoAiChatbot\TicketInboxService;

/**
 * Operator-scoped Seekmodo ticket inbox tools (require Numinix MCP).
 */
abstract class SeekmodoTicketToolBase implements Tool
{
    public function requiredScope(): string
    {
        return 'operator';
    }

    /** @param array<string, mixed> $properties */
    protected function objectSchema(array $properties, array $required = []): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    protected function service(): TicketInboxService
    {
        return new TicketInboxService();
    }

    /** @return never */
    protected function mapException(\Throwable $e): void
    {
        $msg = $e->getMessage();
        $code = $e instanceof \InvalidArgumentException ? 400 : 500;
        throw new ToolException($msg !== '' ? $msg : 'ticket_error', $code);
    }

    protected function adminId(array $args): int
    {
        return max(0, (int) ($args['admin_id'] ?? 0));
    }

    protected function adminName(array $args, string $fallback = 'MCP'): string
    {
        $name = trim((string) ($args['admin_name'] ?? ''));
        return $name !== '' ? $name : $fallback;
    }
}

final class SeekmodoTicketListTool extends SeekmodoTicketToolBase
{
    public function name(): string
    {
        return 'seekmodo.ticket.list';
    }

    public function description(): string
    {
        return 'List Seekmodo chat tickets with status, awaiting-human, archived, date, guest, and search filters.';
    }

    public function isMutating(): bool
    {
        // Mutating flag hides this from chatbot-scope tools/list.
        return true;
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'status' => [
                'type' => 'string',
                'description' => 'open|live|handed_off|resolved|all',
            ],
            'awaiting' => [
                'type' => 'string',
                'description' => 'Set to 1 to only tickets awaiting a human reply',
            ],
            'archived' => [
                'type' => 'string',
                'description' => '0=active (default), 1=archived only, all=both',
            ],
            'date_from' => [
                'type' => 'string',
                'description' => 'YYYY-MM-DD inclusive lower bound on date_updated',
            ],
            'date_to' => [
                'type' => 'string',
                'description' => 'YYYY-MM-DD inclusive upper bound on date_updated',
            ],
            'guest' => [
                'type' => 'string',
                'description' => '1=guests only, 0=customers only, empty=all',
            ],
            'sort' => [
                'type' => 'string',
                'description' => 'updated|created|status',
            ],
            'dir' => [
                'type' => 'string',
                'description' => 'asc|desc',
            ],
            'q' => [
                'type' => 'string',
                'description' => 'Search email, name, session, or preview',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Page size (10–100)',
            ],
            'page' => [
                'type' => 'integer',
            ],
        ]);
    }

    public function execute(ToolContext $ctx, array $args): array
    {
        $ctx->requireMutationAllowed($this->name());
        $limit = max(10, min(100, (int) ($args['limit'] ?? 25)));
        try {
            return $this->service()->list([
                'status' => (string) ($args['status'] ?? ''),
                'awaiting' => (string) ($args['awaiting'] ?? ''),
                'archived' => (string) ($args['archived'] ?? '0'),
                'date_from' => (string) ($args['date_from'] ?? ''),
                'date_to' => (string) ($args['date_to'] ?? ''),
                'guest' => (string) ($args['guest'] ?? ''),
                'sort' => (string) ($args['sort'] ?? 'updated'),
                'dir' => (string) ($args['dir'] ?? 'desc'),
                'q' => (string) ($args['q'] ?? ''),
                'page' => max(1, (int) ($args['page'] ?? 1)),
                'per_page' => $limit,
            ]);
        } catch (\Throwable $e) {
            $this->mapException($e);
        }
    }
}

final class SeekmodoTicketGetTool extends SeekmodoTicketToolBase
{
    public function name(): string
    {
        return 'seekmodo.ticket.get';
    }

    public function description(): string
    {
        return 'Get a Seekmodo ticket transcript with action capability flags. Internal notes included only for admin-scope tokens.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'chat_id' => ['type' => 'integer'],
        ], ['chat_id']);
    }

    public function execute(ToolContext $ctx, array $args): array
    {
        $ctx->requireMutationAllowed($this->name());
        $includeNotes = $ctx->scope === 'admin';
        try {
            return $this->service()->get((int) ($args['chat_id'] ?? 0), $includeNotes);
        } catch (\Throwable $e) {
            $this->mapException($e);
        }
    }
}

final class SeekmodoTicketPollTool extends SeekmodoTicketToolBase
{
    public function name(): string
    {
        return 'seekmodo.ticket.poll';
    }

    public function description(): string
    {
        return 'Poll Seekmodo inbox counts and awaiting-human previews (matches admin console inbox_poll).';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'limit' => [
                'type' => 'integer',
                'description' => 'Max preview items (default 12)',
            ],
        ]);
    }

    public function execute(ToolContext $ctx, array $args): array
    {
        $ctx->requireMutationAllowed($this->name());
        try {
            return $this->service()->poll((int) ($args['limit'] ?? 12));
        } catch (\Throwable $e) {
            $this->mapException($e);
        }
    }
}

final class SeekmodoTicketReplyTool extends SeekmodoTicketToolBase
{
    public function name(): string
    {
        return 'seekmodo.ticket.reply';
    }

    public function description(): string
    {
        return 'Reply to a Seekmodo ticket. mode=live (widget) or email (handoff email).';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'chat_id' => ['type' => 'integer'],
            'mode' => [
                'type' => 'string',
                'description' => 'live or email',
            ],
            'body' => ['type' => 'string'],
            'admin_id' => ['type' => 'integer'],
            'admin_name' => ['type' => 'string'],
        ], ['chat_id', 'mode', 'body']);
    }

    public function execute(ToolContext $ctx, array $args): array
    {
        $ctx->requireMutationAllowed($this->name());
        if ($ctx->dryRun) {
            return ['dry_run' => true, 'chat_id' => (int) ($args['chat_id'] ?? 0), 'mode' => (string) ($args['mode'] ?? '')];
        }
        try {
            return $this->service()->reply(
                (int) ($args['chat_id'] ?? 0),
                (string) ($args['mode'] ?? ''),
                (string) ($args['body'] ?? ''),
                $this->adminId($args),
                $this->adminName($args)
            );
        } catch (\Throwable $e) {
            $this->mapException($e);
        }
    }
}

final class SeekmodoTicketClaimTool extends SeekmodoTicketToolBase
{
    public function name(): string
    {
        return 'seekmodo.ticket.claim';
    }

    public function description(): string
    {
        return 'Claim a Seekmodo ticket for live human takeover.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'chat_id' => ['type' => 'integer'],
            'admin_id' => ['type' => 'integer'],
            'admin_name' => ['type' => 'string'],
        ], ['chat_id']);
    }

    public function execute(ToolContext $ctx, array $args): array
    {
        $ctx->requireMutationAllowed($this->name());
        if ($ctx->dryRun) {
            return ['dry_run' => true, 'chat_id' => (int) ($args['chat_id'] ?? 0)];
        }
        try {
            return $this->service()->claim(
                (int) ($args['chat_id'] ?? 0),
                $this->adminId($args),
                $this->adminName($args)
            );
        } catch (\Throwable $e) {
            $this->mapException($e);
        }
    }
}

final class SeekmodoTicketResumeAiTool extends SeekmodoTicketToolBase
{
    public function name(): string
    {
        return 'seekmodo.ticket.resume_ai';
    }

    public function description(): string
    {
        return 'Return a Seekmodo ticket to the AI assistant (status open).';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'chat_id' => ['type' => 'integer'],
            'admin_id' => ['type' => 'integer'],
        ], ['chat_id']);
    }

    public function execute(ToolContext $ctx, array $args): array
    {
        $ctx->requireMutationAllowed($this->name());
        if ($ctx->dryRun) {
            return ['dry_run' => true, 'chat_id' => (int) ($args['chat_id'] ?? 0)];
        }
        try {
            return $this->service()->resumeAi((int) ($args['chat_id'] ?? 0), $this->adminId($args));
        } catch (\Throwable $e) {
            $this->mapException($e);
        }
    }
}

final class SeekmodoTicketResolveTool extends SeekmodoTicketToolBase
{
    public function name(): string
    {
        return 'seekmodo.ticket.resolve';
    }

    public function description(): string
    {
        return 'Mark a Seekmodo ticket resolved.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'chat_id' => ['type' => 'integer'],
            'admin_id' => ['type' => 'integer'],
        ], ['chat_id']);
    }

    public function execute(ToolContext $ctx, array $args): array
    {
        $ctx->requireMutationAllowed($this->name());
        if ($ctx->dryRun) {
            return ['dry_run' => true, 'chat_id' => (int) ($args['chat_id'] ?? 0)];
        }
        try {
            return $this->service()->resolve((int) ($args['chat_id'] ?? 0), $this->adminId($args));
        } catch (\Throwable $e) {
            $this->mapException($e);
        }
    }
}

final class SeekmodoTicketReopenTool extends SeekmodoTicketToolBase
{
    public function name(): string
    {
        return 'seekmodo.ticket.reopen';
    }

    public function description(): string
    {
        return 'Reopen a resolved Seekmodo ticket (handed_off if previously handed off, else open).';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'chat_id' => ['type' => 'integer'],
            'admin_id' => ['type' => 'integer'],
        ], ['chat_id']);
    }

    public function execute(ToolContext $ctx, array $args): array
    {
        $ctx->requireMutationAllowed($this->name());
        if ($ctx->dryRun) {
            return ['dry_run' => true, 'chat_id' => (int) ($args['chat_id'] ?? 0)];
        }
        try {
            return $this->service()->reopen((int) ($args['chat_id'] ?? 0), $this->adminId($args));
        } catch (\Throwable $e) {
            $this->mapException($e);
        }
    }
}

final class SeekmodoTicketNoteTool extends SeekmodoTicketToolBase
{
    public function name(): string
    {
        return 'seekmodo.ticket.note';
    }

    public function description(): string
    {
        return 'Add an internal note to a Seekmodo ticket (not visible to the shopper).';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'chat_id' => ['type' => 'integer'],
            'body' => ['type' => 'string'],
            'admin_id' => ['type' => 'integer'],
            'admin_name' => ['type' => 'string'],
        ], ['chat_id', 'body']);
    }

    public function execute(ToolContext $ctx, array $args): array
    {
        $ctx->requireMutationAllowed($this->name());
        if ($ctx->dryRun) {
            return ['dry_run' => true, 'chat_id' => (int) ($args['chat_id'] ?? 0)];
        }
        try {
            return $this->service()->note(
                (int) ($args['chat_id'] ?? 0),
                (string) ($args['body'] ?? ''),
                $this->adminId($args),
                $this->adminName($args)
            );
        } catch (\Throwable $e) {
            $this->mapException($e);
        }
    }
}

final class SeekmodoTicketArchiveTool extends SeekmodoTicketToolBase
{
    public function name(): string
    {
        return 'seekmodo.ticket.archive';
    }

    public function description(): string
    {
        return 'Archive a Seekmodo ticket (hide from default inbox list).';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'chat_id' => ['type' => 'integer'],
        ], ['chat_id']);
    }

    public function execute(ToolContext $ctx, array $args): array
    {
        $ctx->requireMutationAllowed($this->name());
        if ($ctx->dryRun) {
            return ['dry_run' => true, 'chat_id' => (int) ($args['chat_id'] ?? 0)];
        }
        try {
            return $this->service()->archive((int) ($args['chat_id'] ?? 0));
        } catch (\Throwable $e) {
            $this->mapException($e);
        }
    }
}

final class SeekmodoTicketUnarchiveTool extends SeekmodoTicketToolBase
{
    public function name(): string
    {
        return 'seekmodo.ticket.unarchive';
    }

    public function description(): string
    {
        return 'Restore an archived Seekmodo ticket to the active inbox.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'chat_id' => ['type' => 'integer'],
        ], ['chat_id']);
    }

    public function execute(ToolContext $ctx, array $args): array
    {
        $ctx->requireMutationAllowed($this->name());
        if ($ctx->dryRun) {
            return ['dry_run' => true, 'chat_id' => (int) ($args['chat_id'] ?? 0)];
        }
        try {
            return $this->service()->unarchive((int) ($args['chat_id'] ?? 0));
        } catch (\Throwable $e) {
            $this->mapException($e);
        }
    }
}

final class SeekmodoTicketPresenceTool extends SeekmodoTicketToolBase
{
    public function name(): string
    {
        return 'seekmodo.ticket.presence';
    }

    public function description(): string
    {
        return 'Touch admin presence so the store treats a remote agent as online for live-vs-email UX.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return $this->objectSchema([
            'admin_id' => ['type' => 'integer'],
            'admin_name' => ['type' => 'string'],
        ]);
    }

    public function execute(ToolContext $ctx, array $args): array
    {
        $ctx->requireMutationAllowed($this->name());
        try {
            return $this->service()->presenceTouch($this->adminId($args), $this->adminName($args, ''));
        } catch (\Throwable $e) {
            $this->mapException($e);
        }
    }
}
