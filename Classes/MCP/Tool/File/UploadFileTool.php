<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Upload a binary asset (image, PDF, ...) into the default fileadmin storage.
 *
 * The binary is delivered inline as base64 inside the tool call. The tool decodes
 * it, sanity-checks size/mime/extension, drops it into the requested folder via
 * `ResourceStorage::addFile()` (which also indexes the resulting `sys_file` row),
 * then optionally writes title/alternative/description into `sys_file_metadata`
 * through DataHandler so the change participates in the active workspace.
 *
 * Auto-registered via the inherited `mcp.tool` Symfony tag on ToolInterface.
 */
class UploadFileTool extends AbstractRecordTool
{
    public function getName(): string
    {
        return 'UploadFile';
    }

    public function getSchema(): array
    {
        $maxBytes = $this->getMaxBytes();
        $sizeNote = $maxBytes > 0
            ? sprintf(' Decoded payload must be <= %d MB (TYPO3 BE.maxFileSize).', (int)($maxBytes / 1024 / 1024))
            : '';
        $allowed = $this->getAllowedExtensions();
        $extNote = $allowed !== []
            ? ' Allowed extensions: ' . implode(', ', $allowed) . '.'
            : ' Extensions are subject to TYPO3 BE.fileExtensions and fileDenyPattern.';

        return [
            'description' => 'Upload a binary asset (image or PDF) to the default fileadmin storage '
                . 'and create the corresponding sys_file record. The binary is passed inline as '
                . 'base64; title/alternative/description are written to sys_file_metadata in '
                . 'workspace context. '
                . 'IMPORTANT — image uploads: you (the assistant) have the image in your context '
                . 'because you base64-encoded it for this call. Look at it and generate '
                . '`alternative` (alt text) before invoking the tool. Do not upload an image '
                . 'without alt text — screen-reader users depend on it and the editor expects '
                . 'you to do this work, not them.' . $extNote . $sizeNote,
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'folder' => [
                        'type' => 'string',
                        'description' => 'Destination folder inside the default storage, e.g. "/user_upload/MCP/". '
                            . 'Leading slash is the storage root. Missing folders are created when '
                            . '"createFolder" is true (default).',
                    ],
                    'filename' => [
                        'type' => 'string',
                        'description' => 'File name to save as. Must not contain slashes or "..". '
                            . 'The extension determines accepted mime types.',
                    ],
                    'contentBase64' => [
                        'type' => 'string',
                        'description' => 'Base64-encoded file content.' . $sizeNote,
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'Optional human-readable title (sys_file_metadata.title). '
                            . 'Use when the file has a name distinct from its filename — e.g. a '
                            . 'publication, event, or work title. Skip when redundant with the filename.',
                    ],
                    'alternative' => [
                        'type' => 'string',
                        'description' => 'Alt text for accessibility (sys_file_metadata.alternative). '
                            . 'REQUIRED for image uploads: examine the actual image content and write '
                            . 'a concise sentence describing what is shown (people, objects, action, '
                            . 'setting) — exactly what a screen reader will announce. Do not '
                            . 'paraphrase the filename; describe the picture. Match the editor\'s '
                            . 'site language when the surrounding conversation is in that language. '
                            . 'Omit only for non-image files (PDFs etc.) where alt text does not apply.',
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'Optional longer caption (sys_file_metadata.description). '
                            . 'For images, set this when there is context beyond what `alternative` '
                            . 'already conveys — the person\'s name, the event, the source, the '
                            . 'photographer credit. Leave empty when the alt text already captures '
                            . 'everything worth saying.',
                    ],
                    'conflictMode' => [
                        'type' => 'string',
                        'enum' => ['rename', 'replace', 'cancel'],
                        'description' => 'How to handle an existing file with the same name. '
                            . '"rename" (default) appends a numeric suffix; "replace" overwrites; '
                            . '"cancel" returns an error.',
                    ],
                    'createFolder' => [
                        'type' => 'boolean',
                        'description' => 'Create missing folders along the path. Default: true.',
                    ],
                ],
                'required' => ['folder', 'filename', 'contentBase64'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'idempotentHint' => false,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $folderPath = trim((string)($params['folder'] ?? ''));
        $filename = trim((string)($params['filename'] ?? ''));
        $contentBase64 = (string)($params['contentBase64'] ?? '');
        $title = isset($params['title']) ? (string)$params['title'] : null;
        $alternative = isset($params['alternative']) ? (string)$params['alternative'] : null;
        $description = isset($params['description']) ? (string)$params['description'] : null;
        $conflictRaw = strtolower((string)($params['conflictMode'] ?? 'rename'));
        $autoCreateFolder = !array_key_exists('createFolder', $params) || (bool)$params['createFolder'];

        if ($folderPath === '') {
            return $this->createErrorResult('Parameter "folder" is required.');
        }
        if ($filename === '') {
            return $this->createErrorResult('Parameter "filename" is required.');
        }
        if ($contentBase64 === '') {
            return $this->createErrorResult('Parameter "contentBase64" is required.');
        }
        if (str_contains($filename, '/') || str_contains($filename, '\\') || str_contains($filename, '..')) {
            return $this->createErrorResult('Parameter "filename" must not contain slashes or "..".');
        }
        if (str_contains($folderPath, '..')) {
            return $this->createErrorResult('Parameter "folder" must not contain "..".');
        }

        $extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension === '') {
            return $this->createErrorResult('Filename must include a file extension.');
        }
        $allowedExtensions = $this->getAllowedExtensions();
        if ($allowedExtensions !== [] && !in_array($extension, $allowedExtensions, true)) {
            return $this->createErrorResult(sprintf(
                'Extension ".%s" is not allowed by TYPO3 BE.fileExtensions. Allowed: %s',
                $extension,
                implode(', ', $allowedExtensions)
            ));
        }

        $binary = base64_decode($contentBase64, true);
        if ($binary === false || $binary === '') {
            return $this->createErrorResult('Parameter "contentBase64" is not valid base64 or decodes to empty.');
        }
        $size = strlen($binary);
        $maxBytes = $this->getMaxBytes();
        if ($maxBytes > 0 && $size > $maxBytes) {
            return $this->createErrorResult(sprintf(
                'Decoded payload is %d bytes, exceeds the %d byte limit (TYPO3 BE.maxFileSize).',
                $size,
                $maxBytes
            ));
        }

        $conflictMode = match ($conflictRaw) {
            'replace' => DuplicationBehavior::REPLACE,
            'cancel'  => DuplicationBehavior::CANCEL,
            'rename'  => DuplicationBehavior::RENAME,
            default   => null,
        };
        if ($conflictMode === null) {
            return $this->createErrorResult('Parameter "conflictMode" must be one of: rename, replace, cancel.');
        }

        // Stage the bytes in a temp file so we can hand a path to TYPO3 core.
        $tempPath = GeneralUtility::tempnam('mcp_upload_', '.' . $extension);
        if ($tempPath === '' || file_put_contents($tempPath, $binary) === false) {
            return $this->createErrorResult('Failed to stage the upload to a temporary file.');
        }

        try {
            $storage = $this->getDefaultStorage();
            if ($storage === null) {
                return $this->createErrorResult('No default file storage is configured.');
            }

            $folder = $this->resolveFolder($storage, $folderPath, $autoCreateFolder);

            // addFile() with removeOriginal=true cleans up our temp file on success.
            $file = $storage->addFile($tempPath, $folder, $filename, $conflictMode, true);
            $tempPath = null;

            $fileUid = (int)$file->getUid();
            $metadataUpdated = false;
            if ($fileUid > 0 && ($title !== null || $alternative !== null || $description !== null)) {
                $metadataUpdated = $this->writeMetadata($fileUid, $title, $alternative, $description);
            }

            return $this->createJsonResult([
                'action' => 'upload',
                'fileUid' => $fileUid,
                'identifier' => $file->getIdentifier(),
                'storageUid' => (int)$storage->getUid(),
                'name' => $file->getName(),
                'size' => $size,
                'mimeType' => $file->getMimeType(),
                'metadataUpdated' => $metadataUpdated,
            ]);
        } finally {
            if ($tempPath !== null && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Effective upload size limit in bytes, derived from BE.maxFileSize (KB).
     * Returns 0 when no limit is configured.
     */
    private function getMaxBytes(): int
    {
        $kb = (int)($GLOBALS['TYPO3_CONF_VARS']['BE']['maxFileSize'] ?? 0);
        return $kb > 0 ? $kb * 1024 : 0;
    }

    /**
     * Lower-cased list of extensions accepted by TYPO3's webspace allow list.
     * Empty array means "no explicit allow list" — TYPO3's fileDenyPattern still applies.
     */
    private function getAllowedExtensions(): array
    {
        $raw = (string)($GLOBALS['TYPO3_CONF_VARS']['BE']['fileExtensions']['webspace']['allow'] ?? '');
        if ($raw === '' || $raw === '*') {
            return [];
        }
        $list = array_filter(array_map(
            static fn($e) => strtolower(trim($e)),
            explode(',', $raw)
        ));
        return array_values(array_unique($list));
    }

    private function getDefaultStorage(): ?ResourceStorage
    {
        $storageRepository = GeneralUtility::makeInstance(StorageRepository::class);
        return $storageRepository->getDefaultStorage();
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
            throw new \RuntimeException(sprintf('Folder "%s" does not exist and createFolder is false.', $normalized));
        }

        // Walk segments, creating any that are missing.
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

    /**
     * Write sys_file_metadata in workspace context via DataHandler so the change
     * is recorded as a workspace draft (matching the vendor's WriteTable pattern).
     */
    private function writeMetadata(int $fileUid, ?string $title, ?string $alternative, ?string $description): bool
    {
        $this->ensureTableAccess('sys_file_metadata', 'write');

        $metadataUid = $this->findMetadataUid($fileUid);
        if ($metadataUid === null) {
            return false;
        }

        $fields = [];
        if ($title !== null) {
            $fields['title'] = $title;
        }
        if ($alternative !== null) {
            $fields['alternative'] = $alternative;
        }
        if ($description !== null) {
            $fields['description'] = $description;
        }
        if ($fields === []) {
            return false;
        }

        $datamap = [
            'sys_file_metadata' => [
                $metadataUid => $fields,
            ],
        ];

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($datamap, []);
        $dataHandler->process_datamap();

        return empty($dataHandler->errorLog);
    }

    private function findMetadataUid(int $fileUid): ?int
    {
        $queryBuilder = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file_metadata');
        $row = $queryBuilder
            ->select('uid')
            ->from('sys_file_metadata')
            ->where(
                $queryBuilder->expr()->eq(
                    'file',
                    $queryBuilder->createNamedParameter($fileUid, \Doctrine\DBAL\ParameterType::INTEGER)
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return $row ? (int)$row['uid'] : null;
    }
}