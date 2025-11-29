<?php
/**
 * Penpot Database Extractor
 * 
 * Connects to PostgreSQL and extracts file data.
 * 
 * @package PenpotExtractor
 * @author Penpot Contributors
 * @license MPL-2.0
 */

require_once __DIR__ . '/PenpotBlobDecoder.php';

class PenpotDatabaseExtractor
{
    private PDO $db;
    private string $publicUri;
    private PenpotBlobDecoder $decoder;

    /**
     * Constructor
     * 
     * @param string $host PostgreSQL host
     * @param int $port PostgreSQL port
     * @param string $database Database name
     * @param string $username Username
     * @param string $password Password
     * @param string $publicUri Penpot public URI for asset URLs (e.g., "https://your-penpot.com")
     */
    public function __construct(
        string $host,
        int $port,
        string $database,
        string $username,
        string $password,
        string $publicUri = ''
    ) {
        $dsn = "pgsql:host=$host;port=$port;dbname=$database";
        $this->db = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->publicUri = rtrim($publicUri, '/');
        $this->decoder = new PenpotBlobDecoder();
    }

    /**
     * Get file info without data
     * 
     * @param string $fileId File UUID
     * @return array|null File info or null if not found
     */
    public function getFileInfo(string $fileId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT f.id, f.name, f.project_id, f.created_at, f.modified_at,
                   f.revn, f.version, f.is_shared, f.features,
                   p.team_id
            FROM file AS f
            INNER JOIN project AS p ON (p.id = f.project_id)
            WHERE f.id = :file_id
              AND f.deleted_at IS NULL
        ");
        $stmt->execute(['file_id' => $fileId]);
        
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        // Parse PostgreSQL array for features
        $row['features'] = $this->parsePostgresArray($row['features']);
        
        return $row;
    }

    /**
     * Get file data as JSON
     * 
     * This retrieves the file data blob and decodes it.
     * 
     * IMPORTANT: Modern Penpot (v5 blobs) uses Fressian encoding which
     * cannot be decoded in PHP. For those files, use the Penpot API.
     * 
     * @param string $fileId File UUID
     * @return array File data with metadata
     * @throws Exception If file not found or blob cannot be decoded
     */
    public function getFileData(string $fileId): array
    {
        // First get file info
        $fileInfo = $this->getFileInfo($fileId);
        if (!$fileInfo) {
            throw new Exception("File not found: $fileId");
        }

        // Try to get data from file_data table first (newer storage)
        $stmt = $this->db->prepare("
            SELECT fd.data, fd.backend
            FROM file_data AS fd
            WHERE fd.file_id = :file_id
              AND fd.id = :file_id
              AND fd.type = 'main'
        ");
        $stmt->execute(['file_id' => $fileId]);
        $dataRow = $stmt->fetch();

        // If not in file_data, try legacy data column in file table
        if (!$dataRow || !$dataRow['data']) {
            $stmt = $this->db->prepare("
                SELECT data FROM file WHERE id = :file_id AND deleted_at IS NULL
            ");
            $stmt->execute(['file_id' => $fileId]);
            $legacyRow = $stmt->fetch();
            
            if (!$legacyRow || !$legacyRow['data']) {
                throw new Exception(
                    "File data not found. The file may use external storage. " .
                    "Use the Penpot API instead."
                );
            }
            
            $blobData = $legacyRow['data'];
        } else {
            $blobData = $dataRow['data'];
        }

        // Handle PostgreSQL bytea format
        if (is_resource($blobData)) {
            $blobData = stream_get_contents($blobData);
        }

        // Check blob version before attempting decode
        $version = $this->decoder->getVersion($blobData);
        
        if ($version >= 4) {
            throw new Exception(
                "This file uses Fressian encoding (version $version) which cannot be decoded in PHP. " .
                "Options:\n" .
                "1. Use the Penpot management API: POST /api/management/get-file-json\n" .
                "2. Use the standalone Java decoder tool\n" .
                "3. Export the file using Penpot's export feature"
            );
        }

        // Decode the blob
        $decodedData = $this->decoder->decode($blobData);

        // Get media objects for this file
        $mediaObjects = $this->getFileMediaObjects($fileId);

        return [
            'id' => $fileInfo['id'],
            'name' => $fileInfo['name'],
            'project_id' => $fileInfo['project_id'],
            'team_id' => $fileInfo['team_id'],
            'created_at' => $fileInfo['created_at'],
            'modified_at' => $fileInfo['modified_at'],
            'revn' => (int)$fileInfo['revn'],
            'version' => (int)$fileInfo['version'],
            'is_shared' => (bool)$fileInfo['is_shared'],
            'features' => $fileInfo['features'],
            'data' => $decodedData,
            'media' => $mediaObjects,
        ];
    }

    /**
     * Get media objects for a file with their URLs
     * 
     * @param string $fileId File UUID
     * @return array Media objects with URLs
     */
    public function getFileMediaObjects(string $fileId): array
    {
        $stmt = $this->db->prepare("
            SELECT fmo.id, fmo.name, fmo.width, fmo.height, fmo.mtype,
                   fmo.media_id, fmo.thumbnail_id, fmo.is_local
            FROM file_media_object AS fmo
            WHERE fmo.file_id = :file_id
              AND fmo.deleted_at IS NULL
        ");
        $stmt->execute(['file_id' => $fileId]);
        
        $mediaObjects = [];
        while ($row = $stmt->fetch()) {
            $mediaObj = [
                'id' => $row['id'],
                'name' => $row['name'],
                'width' => (int)$row['width'],
                'height' => (int)$row['height'],
                'mtype' => $row['mtype'],
                'is_local' => (bool)$row['is_local'],
            ];

            // Add media URL if we have a public URI
            if ($this->publicUri && $row['media_id']) {
                $mediaObj['media_url'] = $this->publicUri . '/assets/by-id/' . $row['media_id'];
            } else {
                $mediaObj['media_id'] = $row['media_id'];
            }

            // Add thumbnail URL if available
            if ($this->publicUri && $row['thumbnail_id']) {
                $mediaObj['thumbnail_url'] = $this->publicUri . '/assets/by-id/' . $row['thumbnail_id'];
            } elseif ($row['thumbnail_id']) {
                $mediaObj['thumbnail_id'] = $row['thumbnail_id'];
            }

            $mediaObjects[$row['id']] = $mediaObj;
        }

        return $mediaObjects;
    }

    /**
     * List all files in a project
     * 
     * @param string $projectId Project UUID
     * @return array List of file info
     */
    public function listProjectFiles(string $projectId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, name, created_at, modified_at, revn, is_shared
            FROM file
            WHERE project_id = :project_id
              AND deleted_at IS NULL
            ORDER BY modified_at DESC
        ");
        $stmt->execute(['project_id' => $projectId]);
        
        return $stmt->fetchAll();
    }

    /**
     * List all projects in a team
     * 
     * @param string $teamId Team UUID
     * @return array List of projects
     */
    public function listTeamProjects(string $teamId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, name, created_at, modified_at
            FROM project
            WHERE team_id = :team_id
              AND deleted_at IS NULL
            ORDER BY modified_at DESC
        ");
        $stmt->execute(['team_id' => $teamId]);
        
        return $stmt->fetchAll();
    }

    /**
     * List all teams
     * 
     * @return array List of teams
     */
    public function listTeams(): array
    {
        $stmt = $this->db->query("
            SELECT id, name, created_at, modified_at
            FROM team
            WHERE deleted_at IS NULL
            ORDER BY name
        ");
        
        return $stmt->fetchAll();
    }

    /**
     * Parse PostgreSQL array string to PHP array
     */
    private function parsePostgresArray(?string $arrayStr): array
    {
        if (!$arrayStr || $arrayStr === '{}') {
            return [];
        }

        // Remove curly braces
        $inner = trim($arrayStr, '{}');
        if (empty($inner)) {
            return [];
        }

        // Split by comma, handling quoted strings
        $elements = [];
        $current = '';
        $inQuotes = false;
        
        for ($i = 0; $i < strlen($inner); $i++) {
            $char = $inner[$i];
            
            if ($char === '"' && ($i === 0 || $inner[$i-1] !== '\\')) {
                $inQuotes = !$inQuotes;
            } elseif ($char === ',' && !$inQuotes) {
                $elements[] = trim($current, '"');
                $current = '';
            } else {
                $current .= $char;
            }
        }
        
        if ($current !== '') {
            $elements[] = trim($current, '"');
        }

        return $elements;
    }
}
