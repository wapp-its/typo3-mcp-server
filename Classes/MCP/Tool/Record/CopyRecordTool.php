<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Doctrine\DBAL\ParameterType;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Copy a record (and optionally its children) to another page via DataHandler.
 *
 * Auto-registered via the inherited `mcp.tool` Symfony tag on ToolInterface.
 */
class CopyRecordTool extends AbstractRecordTool
{
    public function getName(): string
    {
        return 'CopyRecord';
    }

    public function getSchema(): array
    {
        $accessibleTables = $this->tableAccessService->getAccessibleTables(false);
        $tableNames = array_keys($accessibleTables);
        sort($tableNames);

        return [
            'description' => 'Copy a record to another page using TYPO3 DataHandler. '
                . 'Handles content elements, IRRE relations (file references, linkbox links, etc.), '
                . 'and — for pages with copyTree>0 — entire subtrees. All operations run in workspace context: '
                . 'the copy is created as a workspace draft and must be published to go live.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'table' => [
                        'type' => 'string',
                        'description' => 'Table of the record to copy.',
                        'enum' => $tableNames,
                    ],
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'UID of the record to copy (the source record, live UID as returned by other MCP tools).',
                    ],
                    'targetPid' => [
                        'type' => 'integer',
                        'description' => 'Destination page UID. For pages, this is the parent page the copy is placed under. '
                            . 'For content records (tt_content etc.) this is the page the copy is placed on.',
                    ],
                    'copyTree' => [
                        'type' => 'integer',
                        'description' => 'Only meaningful for table="pages": how many sub-page levels to copy along with the page. '
                            . '0 (default) copies only the page record itself plus its own content. '
                            . '99 copies the entire subtree. Has no effect for non-page tables.',
                    ],
                ],
                'required' => ['table', 'uid', 'targetPid'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'idempotentHint' => false,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $table = (string)($params['table'] ?? '');
        $sourceUid = isset($params['uid']) ? (int)$params['uid'] : 0;
        $targetPid = isset($params['targetPid']) ? (int)$params['targetPid'] : 0;
        $copyTree = isset($params['copyTree']) ? max(0, (int)$params['copyTree']) : 0;

        if ($table === '') {
            return $this->createErrorResult('Parameter "table" is required.');
        }
        if ($sourceUid <= 0) {
            return $this->createErrorResult('Parameter "uid" must be a positive integer.');
        }
        if ($targetPid <= 0) {
            return $this->createErrorResult('Parameter "targetPid" must be a positive integer.');
        }

        $this->ensureTableAccess($table, 'write');

        $cmdmap = [
            $table => [
                $sourceUid => ['copy' => $targetPid],
            ],
        ];

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        if ($table === 'pages' && $copyTree > 0) {
            $dataHandler->copyTree = $copyTree;
        }
        $dataHandler->start([], $cmdmap);
        $dataHandler->process_cmdmap();

        if (!empty($dataHandler->errorLog)) {
            return $this->createErrorResult(
                "Copy failed:\n" . implode("\n", $dataHandler->errorLog)
            );
        }

        $newUidRaw = $dataHandler->copyMappingArray[$table][$sourceUid] ?? null;
        if ($newUidRaw === null) {
            return $this->createErrorResult('Copy completed but DataHandler returned no new UID.');
        }
        $newWorkspaceUid = (int)$newUidRaw;
        $newLiveUid = $this->resolveLiveUid($table, $newWorkspaceUid);

        return $this->createJsonResult([
            'action' => 'copy',
            'table' => $table,
            'sourceUid' => $sourceUid,
            'newUid' => $newLiveUid,
            'targetPid' => $targetPid,
            'copyTree' => $copyTree,
        ]);
    }

    /**
     * Map a workspace UID (as returned by DataHandler::copyMappingArray) back to
     * the live UID the MCP client sees. In workspace context a copy creates a
     * placeholder live record (t3ver_oid=0, t3ver_state=1) plus a workspace
     * version (t3ver_oid=<placeholder>). We expose the placeholder UID since
     * that is what becomes live after publishing.
     */
    protected function resolveLiveUid(string $table, int $workspaceUid): int
    {
        $currentWorkspace = $GLOBALS['BE_USER']->workspace ?? 0;
        if ($currentWorkspace === 0) {
            return $workspaceUid;
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $record = $queryBuilder
            ->select('t3ver_oid', 't3ver_state')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($workspaceUid, ParameterType::INTEGER)
                )
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!$record) {
            return $workspaceUid;
        }
        if ((int)$record['t3ver_oid'] > 0) {
            return (int)$record['t3ver_oid'];
        }

        return $workspaceUid;
    }
}