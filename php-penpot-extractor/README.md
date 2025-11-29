# Penpot PHP Extractor

A standalone PHP tool for extracting Penpot file data from PostgreSQL and converting it to JSON format.

## Two Approaches

This tool provides two methods to extract Penpot file data:

### 1. Via Penpot Management API (Recommended)
Works with **ALL Penpot versions** and blob formats. Requires:
- Penpot public URL
- Management API key (configured in Penpot)

```bash
# Configure in config.php
'penpot_public_uri' => 'https://your-penpot.com',
'penpot_api_key' => 'your-management-api-key',

# Then use
GET /api.php?action=file_via_api&file_id=<uuid>
```

### 2. Direct Database Access
Works only with **older blob formats (v1, v3)**. Modern Penpot uses Fressian (v4/v5) which cannot be decoded in PHP.

```bash
GET /api.php?action=file&file_id=<uuid>
```

## ⚠️ Important Limitation

**Modern Penpot installations use Fressian encoding (blob versions 4 and 5) which cannot be decoded in PHP.** 

For files created in recent Penpot versions, use the `file_via_api` action or deploy Penpot's management API endpoint.

## Requirements

### For API Approach (Recommended)
- PHP 7.4 or higher with cURL extension
- Network access to Penpot server
- Penpot management API key

### For Direct Database Approach
- PHP 7.4 or higher
- PostgreSQL PDO extension (`php-pgsql`)
- For v1 blobs: LZ4 extension (`php-lz4`) - https://github.com/kjdev/php-ext-lz4
- For v3 blobs: Zstd extension (`php-zstd`) - https://github.com/kjdev/php-ext-zstd

## Installation

1. Copy the files to your web server:
   ```bash
   cp -r php-penpot-extractor /var/www/html/penpot-extractor
   ```

2. Create configuration:
   ```bash
   cd /var/www/html/penpot-extractor
   cp config.example.php config.php
   nano config.php  # Edit with your database credentials
   ```

3. Configure your web server (Nginx example):
   ```nginx
   server {
       listen 80;
       server_name penpot-extractor.yourdomain.com;
       root /var/www/html/penpot-extractor;
       index api.php;

       location / {
           try_files $uri $uri/ /api.php?$query_string;
       }

       location ~ \.php$ {
           fastcgi_pass unix:/var/run/php/php-fpm.sock;
           fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
           include fastcgi_params;
       }
   }
   ```

## API Endpoints

### Get File Data via Penpot API (Recommended)
```bash
GET /api.php?action=file_via_api&file_id=<uuid>
```
Uses Penpot's management API. Works with ALL blob versions. Requires `penpot_api_key` in config.

### Get File Data (Direct Database)
```bash
GET /api.php?action=file&file_id=<uuid>
```
Returns complete file data as JSON including all pages and objects.
**Note:** Only works with v1/v3 blobs.

### Get File Info (without data)
```bash
GET /api.php?action=file_info&file_id=<uuid>
```
Returns file metadata without the heavy data payload.

### Get Media Objects
```bash
GET /api.php?action=media&file_id=<uuid>
```
Returns all media objects (images, thumbnails) for a file with their URLs.

### List Teams
```bash
GET /api.php?action=list_teams
```

### List Projects in Team
```bash
GET /api.php?action=list_projects&team_id=<uuid>
```

### List Files in Project
```bash
GET /api.php?action=list_files&project_id=<uuid>
```

## Response Format

### File Data Response
```json
{
  "id": "file-uuid",
  "name": "My Design",
  "project_id": "project-uuid",
  "team_id": "team-uuid",
  "created_at": "2024-01-01 00:00:00",
  "modified_at": "2024-01-15 12:00:00",
  "revn": 42,
  "version": 48,
  "is_shared": false,
  "features": ["fdata/pointer-map"],
  "data": {
    "id": "file-uuid",
    "pages": ["page-uuid-1", "page-uuid-2"],
    "pages-index": {
      "page-uuid-1": {
        "id": "page-uuid-1",
        "name": "Page 1",
        "objects": {
          "root-uuid": {
            "id": "root-uuid",
            "type": "frame",
            "name": "Root",
            "..."
          }
        }
      }
    }
  },
  "media": {
    "media-object-uuid": {
      "id": "media-object-uuid",
      "name": "image.png",
      "width": 1920,
      "height": 1080,
      "mtype": "image/png",
      "media_url": "https://penpot.example.com/assets/by-id/storage-uuid"
    }
  }
}
```

## Media/Image URLs

Images in Penpot are stored in a separate storage system. The `media` section of the response includes:
- `media_id` - UUID of the storage object
- `media_url` - Full URL to access the image (if `penpot_public_uri` is configured)
- `thumbnail_url` - Thumbnail URL if available

To access images, either:
1. Use the generated URLs directly (requires `penpot_public_uri` in config)
2. Access via: `https://your-penpot-instance/assets/by-id/<media_id>`

## Programmatic Usage

```php
<?php
require_once 'PenpotDatabaseExtractor.php';

$extractor = new PenpotDatabaseExtractor(
    'localhost',
    5432,
    'penpot',
    'penpot_user',
    'password',
    'https://penpot.example.com'
);

// Get file data
$fileData = $extractor->getFileData('file-uuid-here');

// Convert to JSON
$json = json_encode($fileData, JSON_PRETTY_PRINT);
```

## Troubleshooting

### "Fressian encoding" error
Your file uses modern Penpot encoding. Use the Penpot API instead:
```bash
curl -X POST "http://your-penpot/api/management/get-file-json" \
  -H "Authorization: Bearer $MANAGEMENT_KEY" \
  -H "Content-Type: application/json" \
  -d '{"file-id": "your-file-uuid"}'
```

### LZ4/Zstd extension not available
Install the required PHP extensions:
```bash
# Debian/Ubuntu
pecl install lz4
pecl install zstd

# Add to php.ini
extension=lz4.so
extension=zstd.so
```

### Database connection failed
1. Check PostgreSQL is accessible from your PHP server
2. Verify the credentials in `config.php`
3. Ensure `pg_hba.conf` allows connections from your PHP server

## Security Notes

1. **Protect `config.php`** - Contains database credentials
2. **Use HTTPS** - Encrypt all API traffic
3. **Implement authentication** - Add your own auth layer before production use
4. **Restrict access** - Use firewall rules to limit access to the API

## License

This tool is part of Penpot and is licensed under the Mozilla Public License 2.0.
