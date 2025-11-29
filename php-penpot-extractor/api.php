<?php
/**
 * Penpot File Data Extractor API
 * 
 * Simple REST API endpoint to extract Penpot file data as JSON.
 * 
 * Usage:
 *   GET /api.php?action=file&file_id=<uuid>
 *   GET /api.php?action=file_via_api&file_id=<uuid>  (uses Penpot API - works with all versions)
 *   GET /api.php?action=list_teams
 *   GET /api.php?action=list_projects&team_id=<uuid>
 *   GET /api.php?action=list_files&project_id=<uuid>
 *   GET /api.php?action=media&file_id=<uuid>
 * 
 * @package PenpotExtractor
 * @author Penpot Contributors
 * @license MPL-2.0
 */

// Load configuration
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Configuration file not found',
        'message' => 'Please copy config.example.php to config.php and update with your database credentials'
    ]);
    exit;
}

$config = require $configFile;

require_once __DIR__ . '/PenpotDatabaseExtractor.php';
require_once __DIR__ . '/PenpotApiClient.php';

// Set JSON content type
header('Content-Type: application/json; charset=utf-8');

// CORS headers (adjust as needed)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    // Get action from query params
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    switch ($action) {
        case 'file_via_api':
            // Use Penpot management API (works with ALL blob versions)
            $fileId = $_GET['file_id'] ?? $_POST['file_id'] ?? '';
            if (empty($fileId)) {
                throw new InvalidArgumentException('file_id is required');
            }
            
            if (!isValidUuid($fileId)) {
                throw new InvalidArgumentException('Invalid file_id format');
            }

            if (empty($config['penpot_public_uri']) || empty($config['penpot_api_key'])) {
                throw new Exception(
                    'penpot_public_uri and penpot_api_key must be configured in config.php to use this action'
                );
            }

            $apiClient = new PenpotApiClient(
                $config['penpot_public_uri'],
                $config['penpot_api_key']
            );
            $result = $apiClient->getFileData($fileId);
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        default:
            // Initialize database extractor for other actions
            $extractor = new PenpotDatabaseExtractor(
                $config['db_host'],
                $config['db_port'],
                $config['db_name'],
                $config['db_user'],
                $config['db_password'],
                $config['penpot_public_uri'] ?? ''
            );
            handleDatabaseAction($action, $extractor);
            break;
    }

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Bad Request',
        'message' => $e->getMessage()
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database Error',
        'message' => 'Failed to connect to database or execute query'
    ]);
    error_log('Penpot Extractor DB Error: ' . $e->getMessage());
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Error',
        'message' => $e->getMessage()
    ]);
}

/**
 * Handle database-based actions
 */
function handleDatabaseAction(string $action, PenpotDatabaseExtractor $extractor): void
{
    switch ($action) {
        case 'file':
            // Get file data as JSON
            $fileId = $_GET['file_id'] ?? $_POST['file_id'] ?? '';
            if (empty($fileId)) {
                throw new InvalidArgumentException('file_id is required');
            }
            
            if (!isValidUuid($fileId)) {
                throw new InvalidArgumentException('Invalid file_id format');
            }

            $result = $extractor->getFileData($fileId);
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        case 'file_info':
            // Get file info without data
            $fileId = $_GET['file_id'] ?? $_POST['file_id'] ?? '';
            if (empty($fileId)) {
                throw new InvalidArgumentException('file_id is required');
            }
            
            if (!isValidUuid($fileId)) {
                throw new InvalidArgumentException('Invalid file_id format');
            }

            $result = $extractor->getFileInfo($fileId);
            if (!$result) {
                throw new Exception('File not found');
            }
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        case 'media':
            // Get media objects for a file
            $fileId = $_GET['file_id'] ?? $_POST['file_id'] ?? '';
            if (empty($fileId)) {
                throw new InvalidArgumentException('file_id is required');
            }
            
            if (!isValidUuid($fileId)) {
                throw new InvalidArgumentException('Invalid file_id format');
            }

            $result = $extractor->getFileMediaObjects($fileId);
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        case 'list_teams':
            // List all teams
            $result = $extractor->listTeams();
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        case 'list_projects':
            // List projects in a team
            $teamId = $_GET['team_id'] ?? $_POST['team_id'] ?? '';
            if (empty($teamId)) {
                throw new InvalidArgumentException('team_id is required');
            }
            
            if (!isValidUuid($teamId)) {
                throw new InvalidArgumentException('Invalid team_id format');
            }

            $result = $extractor->listTeamProjects($teamId);
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        case 'list_files':
            // List files in a project
            $projectId = $_GET['project_id'] ?? $_POST['project_id'] ?? '';
            if (empty($projectId)) {
                throw new InvalidArgumentException('project_id is required');
            }
            
            if (!isValidUuid($projectId)) {
                throw new InvalidArgumentException('Invalid project_id format');
            }

            $result = $extractor->listProjectFiles($projectId);
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        case 'check_version':
            // Check blob version for a file (without full decode)
            $fileId = $_GET['file_id'] ?? $_POST['file_id'] ?? '';
            if (empty($fileId)) {
                throw new InvalidArgumentException('file_id is required');
            }

            // This is a diagnostic action - get raw blob and check version
            $result = [
                'file_id' => $fileId,
                'message' => 'Use file_info action to get file metadata'
            ];
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode([
                'error' => 'Unknown or missing action',
                'available_actions' => [
                    'file' => 'Get file data as JSON (file_id required) - Only works with v1/v3 blobs',
                    'file_via_api' => 'Get file data via Penpot API (file_id required) - Works with ALL versions',
                    'file_info' => 'Get file metadata without data (file_id required)',
                    'media' => 'Get media objects for a file (file_id required)',
                    'list_teams' => 'List all teams',
                    'list_projects' => 'List projects in a team (team_id required)',
                    'list_files' => 'List files in a project (project_id required)',
                ],
                'note' => 'For modern Penpot files (v4/v5 blobs), use file_via_api action or configure penpot_api_key.'
            ]);
            break;
    }
}

/**
 * Validate UUID format
 */
function isValidUuid(string $uuid): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) === 1;
}
