<?php

namespace Maxcxam\Larsadmin\Console;

use Illuminate\Console\Command;
use Maxcxam\Larsadmin\Mcp\McpGate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

/**
 * Start the local larsadmin MCP server over stdio (ADR-037), or explain why it cannot run.
 *
 * @internal
 */
#[AsCommand(name: 'larsadmin:mcp')]
class McpCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'larsadmin:mcp';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start the Larsadmin MCP server for AI assistants (stdio, local development only)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Errors go to stderr: stdout is the MCP channel of the client that started the command.
        if (! McpGate::mcpInstalled()) {
            return $this->refuse('The larsadmin MCP server needs the laravel/mcp package: composer require laravel/mcp --dev');
        }

        $reason = McpGate::reason($this->laravel);

        if ($reason !== null) {
            return $this->refuse($reason);
        }

        return $this->call('mcp:start', ['handle' => McpGate::HANDLE]);
    }

    /**
     * Report why the server cannot start and return the failure exit code.
     */
    protected function refuse(string $reason): int
    {
        $output = $this->output->getOutput();
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $stderr->writeln('<error>'.$reason.'</error>');

        return self::FAILURE;
    }
}
