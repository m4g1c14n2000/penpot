# Penpot File Data Extraction Guide

## Overview

Penpot stores file data (all pages and design content) in PostgreSQL as ByteA columns. The data goes through multiple encoding steps:

1. **Serialization**: Either [Transit JSON](https://github.com/cognitect/transit-format) or [Fressian](https://github.com/Datomic/fressian)
2. **Compression**: Either LZ4 or Zstd

### Blob Versions

The encoding version is stored in the first 2 bytes of the blob:

| Version | Serialization | Compression |
|---------|---------------|-------------|
| 1       | Transit JSON  | LZ4         |
| 3       | Transit JSON  | Zstd        |
| 4       | Fressian      | Zstd        |
| 5       | Fressian      | LZ4 Frame   |

Version 5 is the current default.

## How to Extract File Data

### Option 1: Using the Management API (Recommended)

The management API endpoint provides a simple way to extract file data as JSON:

```bash
# Using curl with the management API key
curl -X POST "http://your-penpot-instance/api/management/get-file-json" \
  -H "Authorization: Bearer YOUR_MANAGEMENT_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"file-id": "your-file-uuid-here"}'
```

**Request Parameters:**
- `file-id` (required): UUID of the file to extract
- `include-file-info` (optional, default: true): Include file metadata (name, project-id, etc.)
- `format` (optional, default: "json"): Output format - "json" or "transit"

**Response:** JSON object containing the complete file data structure.

### Option 2: Using the REPL (Server-Side)

If you have access to the Penpot backend REPL:

```clojure
(require '[app.srepl.export-file-data :as efd])
(require '[app.main :as main])

;; Get file data as Clojure data structure
(def file-data (efd/get-file-data main/system "your-file-uuid"))

;; Convert to JSON string
(def json-str (efd/file-data->json file-data))

;; Export directly to a file
(efd/export-file-to-json! main/system "your-file-uuid" "/path/to/output.json")
```

### Option 3: Direct Database Query + Decoding

If you need to query the database directly:

```sql
-- Query the raw data
SELECT f.id, f.name, f.data, fd.data as new_data
FROM file AS f
LEFT JOIN file_data AS fd ON (fd.file_id = f.id AND fd.id = f.id)
WHERE f.id = 'your-file-uuid';
```

Then decode using the Clojure utilities:

```clojure
(require '[app.util.blob :as blob])

;; Decode raw bytes
(def decoded-data (blob/decode raw-bytes))
```

## File Data Structure

The decoded file data has the following structure:

```json
{
  "id": "file-uuid",
  "name": "My Design",
  "project-id": "project-uuid",
  "created-at": "2024-01-01T00:00:00Z",
  "modified-at": "2024-01-15T12:30:00Z",
  "revn": 42,
  "version": 48,
  "features": ["fdata/pointer-map", "fdata/objects-map"],
  "data": {
    "id": "file-uuid",
    "version": 48,
    "pages": ["page-uuid-1", "page-uuid-2"],
    "pages-index": {
      "page-uuid-1": {
        "id": "page-uuid-1",
        "name": "Page 1",
        "objects": {
          "root-uuid": {
            "id": "root-uuid",
            "name": "Root Frame",
            "type": "frame",
            "x": 0,
            "y": 0,
            "width": 1920,
            "height": 1080,
            "...": "more shape properties"
          }
        }
      }
    },
    "colors": {},
    "typographies": {},
    "media": {},
    "components": {}
  }
}
```

## Key Data Fields

### Pages Index

The `pages-index` contains all pages in the file, keyed by page UUID. Each page contains:
- `id`: Page UUID
- `name`: Page name
- `objects`: Map of all shapes/objects on the page

### Objects

Each object in the `objects` map has:
- `id`: Object UUID
- `name`: Object name
- `type`: Shape type (frame, rect, circle, text, path, group, etc.)
- `x`, `y`: Position
- `width`, `height`: Dimensions
- `fills`: Fill styles
- `strokes`: Stroke styles
- And many more properties depending on the shape type

### Colors & Typographies

Library assets that can be reused across the file:
- `colors`: Color palette definitions
- `typographies`: Typography/text style definitions

### Components

Reusable components (similar to Figma components):
- Component definitions
- Component instances reference their parent component

## Converting to Other Formats

### To Plain JSON (with type conversion)

The `file-data->json` function automatically converts Clojure-specific types:
- UUIDs → strings
- Keywords → strings (with namespace if present)
- Instants → ISO 8601 strings
- Sets → arrays
- Ratios → floats

### To Transit JSON

Transit JSON preserves type information for round-tripping:

```clojure
(efd/file-data->transit-json file-data {:verbose? true})
```

## Error Handling

Common errors:
- **File not found**: The file UUID doesn't exist
- **Permission denied**: No access to the management API
- **Decoding error**: Corrupted or invalid blob data

## Security Considerations

- The management API requires authentication with the `management-api-key`
- File data may contain sensitive information
- Always validate file-id input to prevent SQL injection (the API does this automatically)
