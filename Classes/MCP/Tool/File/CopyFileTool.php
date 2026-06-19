<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

/**
 * Copy a file or folder inside fileadmin to a different folder, optionally with a new name.
 *
 * For files: creates a new live `sys_file` row at the target identifier, and copies the
 * source's `sys_file_metadata` onto the new row via `MetaDataAspect::save()`. The copy
 * is NOT a workspace draft — it lands live on disk and in the file index.
 *
 * For folders: recursively copies the subtree, creating one new `sys_file` row per file.
 * Cross-storage folder copies are not implemented by core (throws).
 *
 * Auto-registered via the inherited `mcp.tool` Symfony tag on ToolInterface.
 */
class CopyFileTool extends AbstractFileTransferTool
{
    public function getName(): string
    {
        return 'CopyFile';
    }

    protected function getOperation(): string
    {
        return 'copy';
    }
}