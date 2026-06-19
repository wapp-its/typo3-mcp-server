<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

/**
 * Move a file or folder inside fileadmin to a different folder, optionally renaming it.
 *
 * For files: updates the live `sys_file.identifier` and reindexes the row. Existing
 * `sys_file_reference` rows continue to point at the same `sys_file.uid`, so referenced
 * content elements keep working.
 *
 * For folders: TYPO3 walks every contained file and updates each `sys_file` row's
 * identifier. Cross-storage folder moves are not implemented by core (throws).
 *
 * Auto-registered via the inherited `mcp.tool` Symfony tag on ToolInterface.
 */
class MoveFileTool extends AbstractFileTransferTool
{
    public function getName(): string
    {
        return 'MoveFile';
    }

    protected function getOperation(): string
    {
        return 'move';
    }
}