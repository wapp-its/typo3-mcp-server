<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Shared move/copy logic for files and folders inside fileadmin.
 *
 * Concrete subclasses pick the operation ('move' or 'copy') and the
 * filesystem semantics. The transport, schema, source resolution, target
 * folder resolution and conflict-mode parsing are identical between move
 * and copy.
 *
 * Filesystem changes are immediate — TYPO3 does not workspace file content,
 * only `sys_file_metadata` rows. A move updates the live `sys_file`
 * identifier directly; a copy creates a new live `sys_file` row and copies
 * its metadata via `MetaDataAspect::save()`, so the new metadata is also
 * live (no workspace draft).
 */
abstract class AbstractFileTransferTool extends AbstractRecordTool
{
    /**
     * @return 'move'|'copy'
     */
    abstract protected function getOperation(): string;

    protected function getDescriptionHeadline(): string
    {
        return match ($this->getOperation()) {
            'move' => 'Move a file (by sys_file uid) or a folder (by storage identifier) to a different folder, optionally renaming it.',
            'copy' => 'Copy a file (by sys_file uid) or a folder (by storage identifier) to a different folder, optionally with a new name.',
        };
    }

    public function getSchema(): array
    {
        $op = $this->getOperation();

        return [
            'description' => $this->getDescriptionHeadline()
                . ' Provide exactly one of `fileUid` or `folderIdentifier`. '
                . 'Filesystem changes are immediate; only `sys_file_metadata` rows are workspaced — file content '
                . 'and `sys_file.identifier` updates happen on the live storage. '
                . 'Cross-storage folder ' . $op . ' is not implemented by TYPO3 core and will error; cross-storage '
                . 'file ' . $op . ' is supported.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'fileUid' => [
                        'type' => 'integer',
                        'description' => 'sys_file.uid of the file to ' . $op . '. Mutually exclusive with `folderIdentifier`.',
                    ],
                    'folderIdentifier' => [
                        'type' => 'string',
                        'description' => 'Storage path of the folder to ' . $op . ', e.g. "/user_upload/MCP/". '
                            . 'Mutually exclusive with `fileUid`. The folder is resolved inside `storage` (default storage if omitted).',
                    ],
                    'storage' => [
                        'type' => 'integer',
                        'description' => 'sys_file_storage uid of the SOURCE storage when addressing a folder by `folderIdentifier`. '
                            . 'Ignored when `fileUid` is set (the file row already carries its storage). Defaults to the default storage.',
                    ],
                    'targetFolder' => [
                        'type' => 'string',
                        'description' => 'Destination folder path inside the target storage, e.g. "/user_upload/Archive/". '
                            . 'For file operations: the folder that will contain the file after the ' . $op . '. '
                            . 'For folder operations: the PARENT folder under which the source folder will appear.',
                    ],
                    'targetStorage' => [
                        'type' => 'integer',
                        'description' => 'sys_file_storage uid of the destination storage. Defaults to the source storage.',
                    ],
                    'newName' => [
                        'type' => 'string',
                        'description' => 'Optional new filename / folder name in the destination. '
                            . 'Omit to keep the original name. Must not contain slashes or "..".',
                    ],
                    'conflictMode' => [
                        'type' => 'string',
                        'enum' => ['rename', 'replace', 'cancel'],
                        'description' => 'How to handle a name collision in the target folder. '
                            . '"rename" (default) appends a numeric suffix; "cancel" returns an error. '
                            . '"replace" is honoured for files; for folders TYPO3 core only distinguishes rename/cancel '
                            . '(replace is treated the same as the default rename behaviour).',
                    ],
                    'createTargetFolder' => [
                        'type' => 'boolean',
                        'description' => 'Create missing folders along `targetFolder` (and `targetStorage` root if needed). Default: true.',
                    ],
                ],
                'required' => ['targetFolder'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'idempotentHint' => false,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $hasFile = array_key_exists('fileUid', $params) && $params['fileUid'] !== null && $params['fileUid'] !== '';
        $hasFolder = array_key_exists('folderIdentifier', $params) && $params['folderIdentifier'] !== null && $params['folderIdentifier'] !== '';
        if ($hasFile === $hasFolder) {
            return $this->createErrorResult('Provide exactly one of "fileUid" or "folderIdentifier".');
        }

        $targetFolderPath = trim((string)($params['targetFolder'] ?? ''));
        if ($targetFolderPath === '') {
            return $this->createErrorResult('Parameter "targetFolder" is required.');
        }
        if (str_contains($targetFolderPath, '..')) {
            return $this->createErrorResult('Parameter "targetFolder" must not contain "..".');
        }

        $newName = isset($params['newName']) ? trim((string)$params['newName']) : '';
        if ($newName !== '' && (str_contains($newName, '/') || str_contains($newName, '\\') || str_contains($newName, '..'))) {
            return $this->createErrorResult('Parameter "newName" must not contain slashes or "..".');
        }

        $conflictRaw = strtolower((string)($params['conflictMode'] ?? 'rename'));
        $conflictMode = match ($conflictRaw) {
            'replace' => DuplicationBehavior::REPLACE,
            'cancel'  => DuplicationBehavior::CANCEL,
            'rename'  => DuplicationBehavior::RENAME,
            default   => null,
        };
        if ($conflictMode === null) {
            return $this->createErrorResult('Parameter "conflictMode" must be one of: rename, replace, cancel.');
        }

        $autoCreate = !array_key_exists('createTargetFolder', $params) || (bool)$params['createTargetFolder'];

        if ($hasFile) {
            return $this->transferFile(
                (int)$params['fileUid'],
                $targetFolderPath,
                $newName,
                $conflictMode,
                $autoCreate,
                isset($params['targetStorage']) ? (int)$params['targetStorage'] : null,
            );
        }

        return $this->transferFolder(
            (string)$params['folderIdentifier'],
            isset($params['storage']) ? (int)$params['storage'] : null,
            $targetFolderPath,
            $newName,
            $conflictMode,
            $autoCreate,
            isset($params['targetStorage']) ? (int)$params['targetStorage'] : null,
        );
    }

    private function transferFile(
        int $fileUid,
        string $targetFolderPath,
        string $newName,
        DuplicationBehavior $conflictMode,
        bool $autoCreate,
        ?int $targetStorageUid,
    ): CallToolResult {
        if ($fileUid <= 0) {
            return $this->createErrorResult('Parameter "fileUid" must be a positive integer.');
        }

        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        try {
            $file = $resourceFactory->getFileObject($fileUid);
        } catch (\Throwable $e) {
            return $this->createErrorResult(sprintf('File with uid %d not found: %s', $fileUid, $e->getMessage()));
        }
        if (!$file instanceof File) {
            return $this->createErrorResult(sprintf('Resource with uid %d is not a regular file.', $fileUid));
        }

        $targetStorage = $this->resolveStorage($targetStorageUid) ?? $file->getStorage();
        $targetFolder = $this->resolveFolder($targetStorage, $targetFolderPath, $autoCreate);

        if ($this->getOperation() === 'move') {
            $result = $targetStorage->moveFile($file, $targetFolder, $newName !== '' ? $newName : null, $conflictMode);
        } else {
            $result = $targetStorage->copyFile($file, $targetFolder, $newName !== '' ? $newName : null, $conflictMode);
        }

        return $this->createJsonResult([
            'action' => $this->getOperation(),
            'type' => 'file',
            'fileUid' => (int)$result->getUid(),
            'identifier' => $result->getIdentifier(),
            'name' => $result->getName(),
            'storageUid' => (int)$result->getStorage()->getUid(),
            'sourceFileUid' => $fileUid,
        ]);
    }

    private function transferFolder(
        string $folderIdentifier,
        ?int $sourceStorageUid,
        string $targetFolderPath,
        string $newName,
        DuplicationBehavior $conflictMode,
        bool $autoCreate,
        ?int $targetStorageUid,
    ): CallToolResult {
        if (str_contains($folderIdentifier, '..')) {
            return $this->createErrorResult('Parameter "folderIdentifier" must not contain "..".');
        }
        $sourceFolderPath = '/' . trim($folderIdentifier, '/');
        if ($sourceFolderPath === '/') {
            return $this->createErrorResult('Cannot ' . $this->getOperation() . ' the storage root.');
        }

        $sourceStorage = $this->resolveStorage($sourceStorageUid);
        if ($sourceStorage === null) {
            return $this->createErrorResult('No matching source storage found.');
        }
        if (!$sourceStorage->hasFolder($sourceFolderPath)) {
            return $this->createErrorResult(sprintf(
                'Folder "%s" does not exist in storage "%s".',
                $sourceFolderPath,
                $sourceStorage->getName()
            ));
        }
        $sourceFolder = $sourceStorage->getFolder($sourceFolderPath);

        $targetStorage = $targetStorageUid !== null ? $this->resolveStorage($targetStorageUid) : $sourceStorage;
        if ($targetStorage === null) {
            return $this->createErrorResult('No matching target storage found.');
        }
        $targetParentFolder = $this->resolveFolder($targetStorage, $targetFolderPath, $autoCreate);

        if ($this->getOperation() === 'move') {
            $result = $targetStorage->moveFolder($sourceFolder, $targetParentFolder, $newName !== '' ? $newName : null, $conflictMode);
        } else {
            $result = $targetStorage->copyFolder($sourceFolder, $targetParentFolder, $newName !== '' ? $newName : null, $conflictMode);
        }

        return $this->createJsonResult([
            'action' => $this->getOperation(),
            'type' => 'folder',
            'identifier' => $result->getIdentifier(),
            'name' => $result->getName(),
            'storageUid' => (int)$result->getStorage()->getUid(),
            'sourceIdentifier' => $sourceFolderPath,
            'sourceStorageUid' => (int)$sourceStorage->getUid(),
        ]);
    }

    private function resolveStorage(?int $storageUid): ?ResourceStorage
    {
        $repo = GeneralUtility::makeInstance(StorageRepository::class);
        if ($storageUid !== null && $storageUid > 0) {
            return $repo->findByUid($storageUid);
        }
        return $repo->getDefaultStorage();
    }

    private function resolveFolder(ResourceStorage $storage, string $path, bool $autoCreate): Folder
    {
        $normalized = '/' . trim($path, '/');
        if ($normalized === '/') {
            return $storage->getRootLevelFolder();
        }
        if ($storage->hasFolder($normalized)) {
            return $storage->getFolder($normalized);
        }
        if (!$autoCreate) {
            throw new \RuntimeException(sprintf('Folder "%s" does not exist and createTargetFolder is false.', $normalized));
        }

        $segments = array_values(array_filter(explode('/', $normalized), static fn($s) => $s !== ''));
        $cursor = $storage->getRootLevelFolder();
        foreach ($segments as $segment) {
            $childPath = rtrim($cursor->getIdentifier(), '/') . '/' . $segment . '/';
            if ($storage->hasFolder($childPath)) {
                $cursor = $storage->getFolder($childPath);
                continue;
            }
            $cursor = $storage->createFolder($segment, $cursor);
        }
        return $cursor;
    }
}