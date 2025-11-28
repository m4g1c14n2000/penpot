<?php
/**
 * Penpot Blob Decoder
 * 
 * Decodes Penpot file data stored in PostgreSQL ByteA columns.
 * 
 * Penpot uses a layered encoding format:
 * - Header: 2-byte version + 4-byte uncompressed length
 * - Compression: LZ4 or Zstd
 * - Serialization: Transit JSON or Fressian
 * 
 * Blob versions:
 * - v1: Transit JSON + LZ4 (supported)
 * - v3: Transit JSON + Zstd (supported with php-zstd extension)
 * - v4: Fressian + Zstd (NOT supported - requires Java)
 * - v5: Fressian + LZ4 Frame (NOT supported - requires Java)
 * 
 * Requirements:
 * - PHP 7.4+ with LZ4 extension (php-lz4) for v1
 * - PHP Zstd extension (php-zstd) for v3
 * - For v4/v5, use the standalone Java decoder or the Penpot API
 * 
 * @package PenpotExtractor
 * @author Penpot Contributors
 * @license MPL-2.0
 */

class PenpotBlobDecoder
{
    /**
     * Decode a Penpot blob from raw bytes
     * 
     * @param string $blobData Raw binary data from PostgreSQL ByteA
     * @return array Decoded data as associative array
     * @throws Exception If blob format is unsupported or decoding fails
     */
    public function decode(string $blobData): array
    {
        if (strlen($blobData) < 6) {
            throw new Exception("Blob data too short (minimum 6 bytes for header)");
        }

        // Read header (big-endian)
        $version = $this->readShort($blobData, 0);
        $uncompressedLength = $this->readInt($blobData, 2);
        $compressedData = substr($blobData, 6);

        switch ($version) {
            case 1:
                return $this->decodeV1($compressedData, $uncompressedLength);
            case 3:
                return $this->decodeV3($compressedData, $uncompressedLength);
            case 4:
            case 5:
                throw new Exception(
                    "Blob version $version uses Fressian encoding which cannot be decoded in PHP. " .
                    "Use the Penpot management API endpoint /api/management/get-file-json instead, " .
                    "or use the standalone Java decoder tool."
                );
            default:
                throw new Exception("Unsupported blob version: $version");
        }
    }

    /**
     * Get the version of a blob without fully decoding it
     * 
     * @param string $blobData Raw binary data
     * @return int Version number
     */
    public function getVersion(string $blobData): int
    {
        if (strlen($blobData) < 2) {
            throw new Exception("Blob data too short");
        }
        return $this->readShort($blobData, 0);
    }

    /**
     * Decode version 1 blob (Transit JSON + LZ4)
     */
    private function decodeV1(string $compressedData, int $uncompressedLength): array
    {
        if (!function_exists('lz4_uncompress')) {
            throw new Exception(
                "LZ4 extension not available. Install php-lz4: " .
                "https://github.com/kjdev/php-ext-lz4"
            );
        }

        $decompressed = lz4_uncompress($compressedData, $uncompressedLength);
        if ($decompressed === false) {
            throw new Exception("LZ4 decompression failed");
        }

        return $this->decodeTransitJson($decompressed);
    }

    /**
     * Decode version 3 blob (Transit JSON + Zstd)
     */
    private function decodeV3(string $compressedData, int $uncompressedLength): array
    {
        if (!function_exists('zstd_uncompress')) {
            throw new Exception(
                "Zstd extension not available. Install php-zstd: " .
                "https://github.com/kjdev/php-ext-zstd"
            );
        }

        $decompressed = zstd_uncompress($compressedData);
        if ($decompressed === false) {
            throw new Exception("Zstd decompression failed");
        }

        return $this->decodeTransitJson($decompressed);
    }

    /**
     * Decode Transit JSON format
     * 
     * Transit JSON is a JSON format with type information embedded.
     * See: https://github.com/cognitect/transit-format
     */
    private function decodeTransitJson(string $jsonData): array
    {
        $data = json_decode($jsonData, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("JSON decode error: " . json_last_error_msg());
        }

        // Convert Transit-specific encodings to standard values
        return $this->convertTransitValues($data);
    }

    /**
     * Convert Transit-encoded values to standard PHP types
     * 
     * Transit uses special prefixes like "~:" for keywords, "~u" for UUIDs, etc.
     */
    private function convertTransitValues($data)
    {
        if (is_array($data)) {
            // Check if it's an associative array or indexed array
            if ($this->isAssocArray($data)) {
                $result = [];
                foreach ($data as $key => $value) {
                    // Convert Transit-encoded keys
                    $newKey = $this->convertTransitKey($key);
                    $result[$newKey] = $this->convertTransitValues($value);
                }
                return $result;
            } else {
                // Check for Transit tagged values like ["~#u", "uuid-string"]
                if (count($data) === 2 && is_string($data[0]) && strpos($data[0], '~#') === 0) {
                    return $this->decodeTransitTaggedValue($data[0], $data[1]);
                }
                return array_map([$this, 'convertTransitValues'], $data);
            }
        }
        
        if (is_string($data)) {
            return $this->convertTransitString($data);
        }

        return $data;
    }

    /**
     * Convert a Transit-encoded key
     */
    private function convertTransitKey(string $key): string
    {
        // Remove Transit prefixes
        if (strpos($key, '~:') === 0) {
            return substr($key, 2); // Keyword
        }
        if (strpos($key, '~$') === 0) {
            return substr($key, 2); // Symbol
        }
        return $key;
    }

    /**
     * Convert a Transit-encoded string value
     */
    private function convertTransitString(string $value): string
    {
        // Keywords: ~:name
        if (strpos($value, '~:') === 0) {
            return substr($value, 2);
        }
        // Symbols: ~$name
        if (strpos($value, '~$') === 0) {
            return substr($value, 2);
        }
        // UUIDs: ~uxxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
        if (strpos($value, '~u') === 0) {
            return substr($value, 2);
        }
        // Escaped tilde: ~~
        if (strpos($value, '~~') === 0) {
            return '~' . substr($value, 2);
        }
        return $value;
    }

    /**
     * Decode Transit tagged values like ["~#set", [...]]
     */
    private function decodeTransitTaggedValue(string $tag, $value)
    {
        $tagType = substr($tag, 2);
        
        switch ($tagType) {
            case 'set':
                // Convert to array (PHP doesn't have native sets)
                return is_array($value) ? array_values($value) : [$value];
            case 'u':
                // UUID
                return $value;
            case 'm':
                // Timestamp (milliseconds since epoch)
                return date('c', $value / 1000);
            case 'list':
                return is_array($value) ? $value : [$value];
            default:
                // Return as-is for unknown tags
                return $value;
        }
    }

    /**
     * Check if array is associative
     */
    private function isAssocArray(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * Read a big-endian short (2 bytes) from binary data
     */
    private function readShort(string $data, int $offset): int
    {
        $bytes = substr($data, $offset, 2);
        $unpacked = unpack('n', $bytes);
        return $unpacked[1];
    }

    /**
     * Read a big-endian int (4 bytes) from binary data
     */
    private function readInt(string $data, int $offset): int
    {
        $bytes = substr($data, $offset, 4);
        $unpacked = unpack('N', $bytes);
        return $unpacked[1];
    }
}
