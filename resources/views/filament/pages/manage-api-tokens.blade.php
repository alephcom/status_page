<x-filament-panels::page>
    <p class="text-sm text-gray-600 dark:text-gray-400">
        Tokens let AI assistants and agents use the MCP endpoint at <code>{{ url('/mcp') }}</code>.
        A token can do whatever its user can: editors manage incidents and maintenance, admins can also override component statuses.
    </p>

    {{ $this->table }}
</x-filament-panels::page>
