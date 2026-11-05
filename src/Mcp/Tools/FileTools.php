<?php

namespace Ortic\McpServer\Mcp\Tools;

use Concrete\Core\Application\Application;
use Concrete\Core\Attribute\Category\FileCategory;
use Concrete\Core\Entity\File\File as FileEntity;
use Concrete\Core\File\File;
use Concrete\Core\File\FileList;
use Concrete\Core\File\Filesystem;
use Concrete\Core\File\Import\FileImporter;
use Concrete\Core\File\Import\ImportOptions;
use Concrete\Core\File\Type\Type as FileType;
use Concrete\Core\Http\Client\Client as HttpClient;
use Concrete\Core\Permission\Checker;
use Concrete\Core\Tree\Node\Node;
use Concrete\Core\Tree\Node\Type\File as FileNode;
use Concrete\Core\Tree\Node\Type\FileFolder;
use Ortic\McpServer\Mcp\Args;
use Ortic\McpServer\Mcp\Support\Attributes;
use Ortic\McpServer\Mcp\Support\Serializer;
use Ortic\McpServer\Mcp\Tool;
use Ortic\McpServer\Mcp\ToolError;
use Ortic\McpServer\Mcp\ToolProviderInterface;

class FileTools implements ToolProviderInterface
{
    private const MAX_UPLOAD_BYTES = 25 * 1024 * 1024;

    private const TYPES = [
        'image' => FileType::T_IMAGE,
        'video' => FileType::T_VIDEO,
        'text' => FileType::T_TEXT,
        'audio' => FileType::T_AUDIO,
        'document' => FileType::T_DOCUMENT,
        'application' => FileType::T_APPLICATION,
    ];

    public function __construct(private Application $app, private Serializer $serializer, private Attributes $attributes)
    {
    }

    public function getTools(): array
    {
        $fileRef = ['file_id' => ['type' => ['integer', 'string'], 'description' => 'File ID or UUID.']];

        return [
            new Tool(
                name: 'search_files',
                group: 'files',
                title: 'Search files',
                description: 'Search the file manager by keywords, type or extension. Newest files first.',
                properties: [
                    'keywords' => ['type' => 'string', 'description' => 'Search in titles, file names, descriptions and tags.'],
                    'type' => ['type' => 'string', 'enum' => array_keys(self::TYPES)],
                    'extension' => ['type' => 'string', 'description' => 'e.g. "pdf".'],
                    'limit' => ['type' => 'integer', 'description' => 'Max results (1-100, default 25).'],
                    'page' => ['type' => 'integer', 'description' => 'Result page number (default 1).'],
                ],
                required: [],
                readOnly: true,
                destructive: false,
                handler: fn (Args $a) => $this->searchFiles($a),
                idempotent: true
            ),
            new Tool(
                name: 'browse_file_folder',
                group: 'files',
                title: 'Browse file folder',
                description: 'List the subfolders and files of a file manager folder (default: the root folder).',
                properties: [
                    'folder_id' => ['type' => 'integer', 'description' => 'Folder ID. Default: root folder.'],
                ],
                required: [],
                readOnly: true,
                destructive: false,
                handler: fn (Args $a) => $this->browseFolder($a),
                idempotent: true
            ),
            new Tool(
                name: 'get_file',
                group: 'files',
                title: 'Get file',
                description: 'Get a file\'s details, URL and attributes.',
                properties: $fileRef,
                required: ['file_id'],
                readOnly: true,
                destructive: false,
                handler: fn (Args $a) => $this->getFile($a),
                idempotent: true
            ),
            new Tool(
                name: 'upload_file',
                group: 'files',
                title: 'Upload file',
                description: 'Add a file to the file manager, either from base64 content or by downloading a public URL.',
                properties: [
                    'filename' => ['type' => 'string', 'description' => 'File name with extension, e.g. "team.jpg".'],
                    'content_base64' => ['type' => 'string', 'description' => 'File content, base64 encoded.'],
                    'url' => ['type' => 'string', 'description' => 'Public http(s) URL to download instead of content_base64.'],
                    'folder_id' => ['type' => 'integer', 'description' => 'Target folder. Default: root folder.'],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                ],
                required: ['filename'],
                readOnly: false,
                destructive: false,
                handler: fn (Args $a) => $this->uploadFile($a)
            ),
            new Tool(
                name: 'update_file',
                group: 'files',
                title: 'Update file properties',
                description: 'Change a file\'s title, description, tags or attributes (for example the alt text of an image).',
                properties: $fileRef + [
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'tags' => ['type' => 'string', 'description' => 'Newline separated tags.'],
                    'attributes' => ['type' => 'object', 'description' => 'Attribute values keyed by handle.'],
                ],
                required: ['file_id'],
                readOnly: false,
                destructive: true,
                handler: fn (Args $a) => $this->updateFile($a)
            ),
            new Tool(
                name: 'delete_file',
                group: 'files',
                title: 'Delete file',
                description: 'Permanently delete a file and all its versions. This cannot be undone: confirm with the user first.',
                properties: $fileRef,
                required: ['file_id'],
                readOnly: false,
                destructive: true,
                handler: fn (Args $a) => $this->deleteFile($a)
            ),
            new Tool(
                name: 'create_file_folder',
                group: 'files',
                title: 'Create file folder',
                description: 'Create a folder in the file manager.',
                properties: [
                    'name' => ['type' => 'string'],
                    'parent_folder_id' => ['type' => 'integer', 'description' => 'Default: root folder.'],
                ],
                required: ['name'],
                readOnly: false,
                destructive: false,
                handler: fn (Args $a) => $this->createFolder($a)
            ),
        ];
    }

    private function searchFiles(Args $args): array
    {
        $list = new FileList();
        $list->setPermissionsChecker(fn ($file) => (new Checker($file))->canViewFileInFileManager());
        if (($keywords = $args->string('keywords')) !== null && $keywords !== '') {
            $list->filterByKeywords($keywords);
        }
        if (($type = $args->enum('type', array_keys(self::TYPES))) !== null) {
            $list->filterByType(self::TYPES[$type]);
        }
        if (($extension = $args->string('extension')) !== null && $extension !== '') {
            $list->filterByExtension(strtolower(ltrim($extension, '.')));
        }
        $list->sortByDateAddedDescending();
        $pagination = $list->getPagination();
        $pagination->setMaxPerPage($args->limit());
        $pagination->setCurrentPage(max(1, (int) $args->int('page', 1)));
        $files = [];
        foreach ($pagination->getCurrentPageResults() as $file) {
            $files[] = $this->serializer->file($file);
        }

        return [
            'total' => (int) $pagination->getTotalResults(),
            'page' => $pagination->getCurrentPage(),
            'total_pages' => $pagination->getNbPages(),
            'files' => $files,
        ];
    }

    private function browseFolder(Args $args): array
    {
        $folder = $this->folder($args->int('folder_id'));
        if (!(new Checker($folder))->canViewTreeNode()) {
            throw new ToolError('You cannot view this folder.');
        }
        $folder->populateDirectChildrenOnly();
        $folders = [];
        $files = [];
        foreach ($folder->getChildNodes() as $node) {
            if ($node instanceof FileFolder) {
                $folders[] = ['id' => (int) $node->getTreeNodeID(), 'name' => $node->getTreeNodeDisplayName('text')];
            } elseif ($node instanceof FileNode) {
                $file = $node->getTreeNodeFileObject();
                if ($file && (new Checker($file))->canViewFileInFileManager()) {
                    $files[] = $this->serializer->file($file);
                }
            }
        }
        $parent = $folder->getTreeNodeParentObject();

        return [
            'folder' => [
                'id' => (int) $folder->getTreeNodeID(),
                'name' => $folder->getTreeNodeDisplayName('text'),
                'parent_id' => $parent instanceof FileFolder ? (int) $parent->getTreeNodeID() : null,
            ],
            'folders' => $folders,
            'files' => $files,
        ];
    }

    private function getFile(Args $args): array
    {
        $file = $this->file($args);
        if (!(new Checker($file))->canViewFileInFileManager()) {
            throw new ToolError('You cannot view this file.');
        }
        $data = $this->serializer->file($file, true);
        $version = $file->getApprovedVersion();
        $data['download_url'] = $version ? (string) $version->getDownloadURL() : null;
        $data['versions'] = count($file->getFileVersions());

        return $data;
    }

    private function uploadFile(Args $args): array
    {
        $filename = basename(str_replace('\\', '/', $args->requireString('filename')));
        $folder = $this->folder($args->int('folder_id'));
        $permissions = new Checker($folder);
        if (!$permissions->canAddFiles()) {
            throw new ToolError('You cannot add files to this folder.');
        }
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!$permissions->canAddFileType($extension)) {
            throw new ToolError(sprintf('Files with the extension "%s" are not allowed.', $extension));
        }

        $content = null;
        if ($args->has('content_base64')) {
            $content = base64_decode((string) $args->string('content_base64'), true);
            if ($content === false) {
                throw new ToolError('content_base64 is not valid base64.');
            }
        } elseif ($args->has('url')) {
            $content = $this->download((string) $args->string('url'));
        } else {
            throw new ToolError('Provide content_base64 or url.');
        }
        if (strlen($content) > self::MAX_UPLOAD_BYTES) {
            throw new ToolError('The file is larger than 25 MB.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'mcp');
        try {
            file_put_contents($tmp, $content);
            $options = $this->app->make(ImportOptions::class);
            $options->setImportToFolder($folder);
            $version = $this->app->make(FileImporter::class)->importLocalFile($tmp, $filename, $options);
        } finally {
            @unlink($tmp);
        }
        if (($title = $args->string('title')) !== null) {
            $version->updateTitle($title);
        }
        if (($description = $args->string('description')) !== null) {
            $version->updateDescription($description);
        }

        return $this->serializer->file($version->getFile());
    }

    private function updateFile(Args $args): array
    {
        $file = $this->file($args);
        if (!(new Checker($file))->canEditFileProperties()) {
            throw new ToolError('You cannot edit this file.');
        }
        $version = $file->getVersionToModify();
        if (($title = $args->string('title')) !== null) {
            $version->updateTitle($title);
        }
        if (($description = $args->string('description')) !== null) {
            $version->updateDescription($description);
        }
        if (($tags = $args->string('tags')) !== null) {
            $version->updateTags($tags);
        }
        if (($attributes = $args->object('attributes')) !== null && $attributes !== []) {
            $this->attributes->apply($version, $this->app->make(FileCategory::class), $attributes);
        }

        return $this->serializer->file(File::getByID($file->getFileID()), true);
    }

    private function deleteFile(Args $args): array
    {
        $file = $this->file($args);
        if (!(new Checker($file))->canDeleteFile()) {
            throw new ToolError('You cannot delete this file.');
        }
        $summary = $this->serializer->file($file);
        $file->delete();

        return ['deleted' => $summary];
    }

    private function createFolder(Args $args): array
    {
        $parent = $this->folder($args->int('parent_folder_id'));
        if (!(new Checker($parent))->canAddTreeSubNode()) {
            throw new ToolError('You cannot create folders here.');
        }
        $folder = (new Filesystem())->addFolder($parent, $args->requireString('name'));

        return ['id' => (int) $folder->getTreeNodeID(), 'name' => $folder->getTreeNodeDisplayName('text'), 'parent_id' => (int) $parent->getTreeNodeID()];
    }

    private function file(Args $args): FileEntity
    {
        $id = trim((string) $args->string('file_id', null, true));
        $file = ctype_digit($id) ? File::getByID((int) $id) : File::getByUUIDOrID($id);
        if (!$file) {
            throw new ToolError('File not found.');
        }

        return $file;
    }

    private function folder(?int $folderId): FileFolder
    {
        if (!$folderId) {
            return (new Filesystem())->getRootFolder();
        }
        $folder = Node::getByID($folderId);
        if (!$folder instanceof FileFolder) {
            throw new ToolError('Folder not found.');
        }

        return $folder;
    }

    private function download(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            throw new ToolError('Only http and https URLs can be downloaded.');
        }
        $ips = gethostbynamel($parts['host']) ?: [];
        if ($ips === []) {
            throw new ToolError('The host of the URL cannot be resolved.');
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new ToolError('URLs pointing to private or local network addresses are not allowed.');
            }
        }
        try {
            $response = $this->app->make(HttpClient::class)->request('GET', $url, [
                'timeout' => 30,
                'allow_redirects' => ['max' => 3, 'protocols' => ['http', 'https']],
                'headers' => ['User-Agent' => 'ConcreteCMS-MCP/1.0'],
            ]);
        } catch (\Throwable $e) {
            throw new ToolError('Download failed: ' . $e->getMessage());
        }
        if ($response->getStatusCode() !== 200) {
            throw new ToolError('Download failed with HTTP status ' . $response->getStatusCode());
        }

        return (string) $response->getBody();
    }
}
