# Penpot File Extractor (Clojure Standalone)

A standalone Clojure tool for extracting Penpot file data from PostgreSQL and converting it to JSON. This tool works with **ALL blob versions** including v4 and v5 (Fressian format).

## Features

- **No Penpot installation required** - Runs independently on any server with Clojure
- **Supports all blob versions** (v1, v3, v4, v5)
- **Direct PostgreSQL access** - Connect to your Penpot database
- **JSON output** - Convert file data to readable JSON
- **Media URL resolution** - Optional image/asset URL generation

## Requirements

- Java 11 or higher
- Clojure CLI tools (https://clojure.org/guides/install_clojure)
- Network access to your Penpot PostgreSQL database

## Installation

1. **Install Java** (if not already installed):
   ```bash
   # Ubuntu/Debian
   sudo apt install openjdk-17-jdk
   
   # macOS
   brew install openjdk@17
   ```

2. **Install Clojure CLI**:
   ```bash
   # Linux
   curl -L -O https://github.com/clojure/brew-install/releases/latest/download/linux-install.sh
   chmod +x linux-install.sh
   sudo ./linux-install.sh
   
   # macOS
   brew install clojure/tools/clojure
   
   # Windows
   # Use WSL or follow: https://clojure.org/guides/install_clojure#_windows
   ```

3. **Copy this folder** to your server:
   ```bash
   scp -r clj-penpot-extractor user@your-server:/opt/
   ```

## Usage

### Set Environment Variables

```bash
export PENPOT_DB_HOST=localhost
export PENPOT_DB_PORT=5432
export PENPOT_DB_NAME=penpot
export PENPOT_DB_USER=penpot
export PENPOT_DB_PASSWORD=your-password
export PENPOT_PUBLIC_URI=https://your-penpot.com  # Optional, for media URLs
```

### Commands

```bash
cd /opt/clj-penpot-extractor

# Extract file data as JSON
clj -M:run extract <file-uuid>

# Get file info without data
clj -M:run info <file-uuid>

# List media objects with URLs
clj -M:run media <file-uuid>

# List files in a project
clj -M:run list-files <project-uuid>

# List projects in a team
clj -M:run list-projects <team-uuid>

# List all teams
clj -M:run list-teams

# Check blob version of a file
clj -M:run version <file-uuid>

# Show help
clj -M:run help
```

### Examples

```bash
# Check blob version
clj -M:run version 550e8400-e29b-41d4-a716-446655440000
# Output: {"file-id": "550e8400-...", "blob-version": 4, "format": "Fressian + Zstd"}

# Extract file data
clj -M:run extract 550e8400-e29b-41d4-a716-446655440000 > file-data.json

# List all teams to find team IDs
clj -M:run list-teams

# List projects in a team
clj -M:run list-projects <team-uuid>

# List files in a project
clj -M:run list-files <project-uuid>
```

## Output Format

The extracted JSON has this structure:

```json
{
  "id": "file-uuid",
  "name": "My Design",
  "project-id": "project-uuid",
  "created-at": "2024-01-01T00:00:00Z",
  "modified-at": "2024-01-15T12:00:00Z",
  "revn": 42,
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
  }
}
```

## Media URLs

When using the `media` command with `PENPOT_PUBLIC_URI` set:

```json
[
  {
    "id": "media-uuid",
    "name": "image.png",
    "width": 1920,
    "height": 1080,
    "mtype": "image/png",
    "media_id": "storage-uuid",
    "media_url": "https://your-penpot.com/assets/by-id/storage-uuid",
    "thumbnail_id": "thumb-uuid",
    "thumbnail_url": "https://your-penpot.com/assets/by-id/thumb-uuid"
  }
]
```

## Troubleshooting

### Connection Issues
- Ensure PostgreSQL is accessible from your server
- Check firewall rules allow connections on port 5432
- Verify username/password are correct
- Make sure `pg_hba.conf` allows connections from your server's IP

### "Unsupported blob version" Error
- This tool supports versions 1, 3, 4, and 5
- If you see an unsupported version, please report it

### Memory Issues
For very large files, increase Java heap:
```bash
clj -J-Xmx4g -M:run extract <file-uuid>
```

## Dependencies

This project uses only standard Clojure libraries:
- `org.clojure/data.fressian` - Fressian decoder
- `com.cognitect/transit-clj` - Transit decoder
- `com.github.luben/zstd-jni` - Zstd decompression
- `org.lz4/lz4-java` - LZ4 decompression
- `next.jdbc` - PostgreSQL access
- `org.postgresql/postgresql` - PostgreSQL driver

## License

This project is part of Penpot and is licensed under the Mozilla Public License 2.0.
