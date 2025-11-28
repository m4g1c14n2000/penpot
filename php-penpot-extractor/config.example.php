<?php
/**
 * Penpot Extractor Configuration Example
 * 
 * Copy this file to config.php and update with your actual values.
 */

return [
    // PostgreSQL connection settings
    // (Required for direct database access - actions: file, list_*, media, file_info)
    'db_host' => 'localhost',
    'db_port' => 5432,
    'db_name' => 'penpot',
    'db_user' => 'penpot',
    'db_password' => 'your_password_here',
    
    // Penpot public URI (used to generate asset URLs)
    // This should be the public URL where Penpot is accessible
    // Example: 'https://design.example.com'
    'penpot_public_uri' => '',
    
    // Penpot Management API Key (Required for file_via_api action)
    // This is configured in Penpot's backend settings (PENPOT_MANAGEMENT_API_KEY)
    // The API approach works with ALL blob versions including Fressian (v4/v5)
    'penpot_api_key' => '',
];
