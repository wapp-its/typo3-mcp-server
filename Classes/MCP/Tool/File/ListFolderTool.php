<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Hn\McpServer\Service\SiteInformationService;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * List sub-folders and files inside a fileadmin folder.
 *
 * Two redaktor flows:
 *  - find an existing image to reference from a content element (use the returned `uid`)
 *  - discover a target folder before calling UploadFile (use the returned folder `identifier`)
 *
 * ResourceStorage permission evaluation is on by default in backend context, so
 * non-admin users only see folders/files inside their file mounts. Listing the
 * storage root therefore behaves like "show me my mount points".
 *
 * Auto-registered via the inherited `mcp.tool` Symfony tag on ToolInterface.
 */
class ListFolderTool extends AbstractRecordTool
{
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 500;

    public function __construct(
        protected readonly SiteInformationService $siteInformationService,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'ListFolder';
    }

    public function getSchema(): array
    {
        return [
            'description' => 'List sub-folders and files inside a fileadmin folder. '
                . 'Use this to find an existing image to reference from a content element '
                . '(the file `uid` is what sys_file_reference points at), or to discover a '
                . 'target folder before calling UploadFile. Respects the editor\'s file '
                . 'mounts: listing "/" returns the accessible mount roots for non-admin users. '
                . 'Files include metadata (mime type, alt text, dimensions, public URL).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'folder' => [
                        'type' => 'string',
                        'description' => 'Folder path inside the storage, e.g. "/user_upload/MCP/". '
                            . 'Default "/" lists the storage root.',
                    ],
                    'storage' => [
                        'type' => 'integer',
                        'description' => 'sys_file_storage uid. Defaults to the default storage (fileadmin).',
                    ],
                    'filenameContains' => [
                        'type' => 'string',
                        'description' => 'Case-insensitive substring filter on the filename.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Max files to return. Default ' . self::DEFAULT_LIMIT
                            . ', capped at ' . self::MAX_LIMIT . '. Folder listing is never paginated.',
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'File offset for pagination. Default 0.',
                    ],
                ],
                'required' => [],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $folderPath = trim((string)($params['folder'] ?? '/'));
        if ($folderPath === '') {
            $folderPath = '/';
        }
        if (str_contains($folderPath, '..')) {
            return $this->createErrorResult('Parameter "folder" must not contain "..".');
        }

        $storage = $this->resolveStorage($params['storage'] ?? null);
        if ($storage === null) {
            return $this->createErrorResult('No matching file storage found.');
        }
        if (!$storage->isOnline()) {
            return $this->createErrorResult(sprintf('Storage "%s" is offline.', $storage->getName()));
        }

        $normalized = '/' . trim($folderPath, '/');
        if ($normalized !== '/' && !$storage->hasFolder($normalized)) {
            return $this->createErrorResult(sprintf(
                'Folder "%s" does not exist in storage "%s".',
                $normalized,
                $storage->getName()
            ));
        }

        try {
            $folder = $normalized === '/' ? $storage->getRootLevelFolder() : $storage->getFolder($normalized);
        } catch (\Throwable $e) {
            return $this->createErrorResult('Folder not accessible: ' . $e->getMessage());
        }

        $filenameContains = isset($params['filenameContains'])
            ? trim((string)$params['filenameContains'])
            : '';
        $limit = (int)($params['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, (int)($params['offset'] ?? 0));

        $folders = $this->listSubfolders($folder);
        [$files, $total] = $this->listFiles($folder, $filenameContains, $offset, $limit);

        return $this->createJsonResult([
            'storage' => [
                'uid' => (int)$storage->getUid(),
                'name' => $storage->getName(),
            ],
            'folder' => [
                'identifier' => $folder->getIdentifier(),
                'name' => $folder->getName(),
                'parent' => $this->parentIdentifier($folder),
            ],
            'folders' => $folders,
            'files' => $files,
            'pagination' => [
                'total' => $total,
                'returned' => count($files),
                'offset' => $offset,
                'limit' => $limit,
                'hasMore' => ($offset + count($files)) < $total,
            ],
        ]);
    }

    private function resolveStorage(mixed $storageUid): ?ResourceStorage
    {
        $repo = GeneralUtility::makeInstance(StorageRepository::class);
        if ($storageUid !== null && $storageUid !== '') {
            return $repo->findByUid((int)$storageUid);
        }
        return $repo->getDefaultStorage();
    }

    /**
     * @return array<int, array{identifier: string, name: string}>
     */
    private function listSubfolders(Folder $folder): array
    {
        $rows = [];
        foreach ($folder->getSubfolders() as $sub) {
            $rows[] = [
                'identifier' => $sub->getIdentifier(),
                'name' => $sub->getName(),
            ];
        }
        usort($rows, static fn(array $a, array $b) => strnatcasecmp($a['name'], $b['name']));
        return $rows;
    }

    /**
     * Uses the same Folder/ResourceStorage API as cms-filelist. The optional
     * filename filter is applied via a storage-level name filter (the
     * mechanism core uses for hide-file rules etc.); we save and restore
     * existing filters so we don't leak state into the cached storage
     * instance.
     *
     * Folder-scope permission is already enforced by the caller: we only get
     * here after `$storage->getFolder($path)` returned without throwing, which
     * means the BE user has read access via the file mounts.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function listFiles(Folder $folder, string $filenameContains, int $offset, int $limit): array
    {
        $storage = $folder->getStorage();
        $previousFilters = $storage->getFileAndFolderNameFilters();
        try {
            if ($filenameContains !== '') {
                $needle = $filenameContains;
                $storage->setFileAndFolderNameFilters([
                    ...$previousFilters,
                    static fn($itemName) => stripos((string)$itemName, $needle) !== false ? true : -1,
                ]);
            }

            $total = $folder->getFileCount();
            $files = $folder->getFiles($offset, $limit, Folder::FILTER_MODE_USE_OWN_AND_STORAGE_FILTERS, false, 'name', false);
        } finally {
            $storage->setFileAndFolderNameFilters($previousFilters);
        }

        $rows = [];
        foreach ($files as $file) {
            $rows[] = $this->serializeFile($file);
        }
        return [$rows, $total];
    }

    private function serializeFile(File $file): array
    {
        $extension = strtolower($file->getExtension());
        $isImage = str_starts_with((string)$file->getMimeType(), 'image/');

        $row = [
            'uid' => (int)$file->getUid(),
            'identifier' => $file->getIdentifier(),
            'name' => $file->getName(),
            'extension' => $extension,
            'mimeType' => $file->getMimeType(),
            'size' => (int)$file->getSize(),
            'isImage' => $isImage,
        ];

        $properties = $file->getProperties();
        foreach (['title', 'alternative', 'description'] as $metaKey) {
            $value = $properties[$metaKey] ?? null;
            if (is_string($value) && $value !== '') {
                $row[$metaKey] = $value;
            }
        }
        if ($isImage) {
            $width = (int)($properties['width'] ?? 0);
            $height = (int)($properties['height'] ?? 0);
            if ($width > 0 && $height > 0) {
                $row['width'] = $width;
                $row['height'] = $height;
            }
        }

        try {
            $publicUrl = $file->getPublicUrl();
            if (is_string($publicUrl) && $publicUrl !== '') {
                $row['publicUrl'] = $this->siteInformationService->makeAbsoluteUrl($publicUrl);
            }
        } catch (\Throwable) {
            // getPublicUrl can fail for offline storages or missing drivers — omit silently.
        }

        return $row;
    }

    private function parentIdentifier(Folder $folder): ?string
    {
        $identifier = $folder->getIdentifier();
        if ($identifier === '/' || $identifier === '') {
            return null;
        }
        $trimmed = rtrim($identifier, '/');
        $parent = substr($trimmed, 0, strrpos($trimmed, '/') + 1);
        return $parent === '' ? '/' : $parent;
    }
}
