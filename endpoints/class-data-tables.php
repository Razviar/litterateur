<?php
/**
 * Data Tables endpoint for Texter API
 * 
 * Handles syncing structured data to custom database tables
 */

if (!defined('ABSPATH')) {
    exit;
}

class Texter_API_Endpoint_Data_Tables {
    
    /**
     * Table prefix for structured data tables
     */
    private $table_prefix = 'data_';
    
    /**
     * Register routes
     *
     * @param string $namespace
     */
    public function register_routes($namespace) {
        register_rest_route($namespace, '/data-tables', array(
            array(
                'methods' => 'POST',
                'callback' => array($this, 'sync_data_table'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
            ),
            array(
                'methods' => 'GET',
                'callback' => array($this, 'list_data_tables'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
            ),
        ));
        
        register_rest_route($namespace, '/data-tables/(?P<slug>[a-z0-9_-]+)', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'get_data_table'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
            ),
            array(
                'methods' => 'DELETE',
                'callback' => array($this, 'delete_data_table'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
            ),
        ));
    }
    
    /**
     * Get full table name with WordPress prefix
     *
     * @param string $slug
     * @return string
     */
    private function get_table_name($slug) {
        global $wpdb;
        return $wpdb->prefix . $this->table_prefix . sanitize_key($slug);
    }
    
    /**
     * Map field type to MySQL column type
     *
     * @param string $type
     * @return string
     */
    private function map_field_type($type) {
        $type_map = array(
            'string' => 'VARCHAR(255)',
            'text' => 'TEXT',
            'number' => 'DECIMAL(10,2)',
            'integer' => 'INT',
            'boolean' => 'TINYINT(1)',
            'url' => 'VARCHAR(2048)',
            'image' => 'VARCHAR(2048)',
            'date' => 'DATE',
            'datetime' => 'DATETIME',
            'array' => 'JSON',
            'object' => 'JSON',
            'json' => 'JSON',
        );
        
        return isset($type_map[$type]) ? $type_map[$type] : 'TEXT';
    }
    
    /**
     * Parse structure definition
     * Accepts array format: [{name: "field_name", type: "field_type"}, ...]
     *
     * @param mixed $structure
     * @return array
     */
    private function parse_structure($structure) {
        $fields = array();

        // Handle structure as array of {name, type} objects
        if (is_array($structure)) {
            foreach ($structure as $field) {
                if (is_array($field) && isset($field['name']) && isset($field['type'])) {
                    $field_name = sanitize_key($field['name']);
                    $field_type = strtolower(trim($field['type']));

                    if (!empty($field_name)) {
                        $fields[$field_name] = $field_type;
                    }
                }
            }
        }

        return $fields;
    }
    
    /**
     * Check if table exists
     *
     * @param string $table_name
     * @return bool
     */
    private function table_exists($table_name) {
        global $wpdb;
        $result = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table_name
        ));
        return $result === $table_name;
    }
    
    /**
     * Get existing table columns
     *
     * @param string $table_name
     * @return array
     */
    private function get_table_columns($table_name) {
        global $wpdb;
        $columns = array();
        
        $results = $wpdb->get_results("DESCRIBE `{$table_name}`", ARRAY_A);
        
        if ($results) {
            foreach ($results as $row) {
                $columns[$row['Field']] = array(
                    'type' => $row['Type'],
                    'null' => $row['Null'],
                    'key' => $row['Key'],
                    'default' => $row['Default'],
                );
            }
        }
        
        return $columns;
    }
    
    /**
     * Create or update table structure
     *
     * @param string $table_name
     * @param array $fields
     * @return bool|WP_Error
     */
    private function ensure_table_structure($table_name, $fields) {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        if (!$this->table_exists($table_name)) {
            // Create new table - dbDelta doesn't support COMMENT, so we add them after
            $column_defs = array(
                'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
                'slug VARCHAR(255) NOT NULL',
                'name VARCHAR(512)',
            );

            foreach ($fields as $field_name => $field_type) {
                // Skip reserved fields
                if (in_array($field_name, array('id', 'slug', 'name', 'created_at', 'updated_at'))) {
                    continue;
                }
                $mysql_type = $this->map_field_type($field_type);
                $column_defs[] = "`{$field_name}` {$mysql_type}";
            }

            $column_defs[] = 'created_at DATETIME DEFAULT CURRENT_TIMESTAMP';
            $column_defs[] = 'updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';
            $column_defs[] = 'PRIMARY KEY (id)';
            $column_defs[] = 'UNIQUE KEY slug_unique (slug)';

            $sql = "CREATE TABLE `{$table_name}` (\n" . implode(",\n", $column_defs) . "\n) {$charset_collate};";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);

            if (!$this->table_exists($table_name)) {
                return new WP_Error('table_creation_failed', 'Failed to create table');
            }

            // Add comments to columns using ALTER TABLE (dbDelta doesn't support COMMENT)
            foreach ($fields as $field_name => $field_type) {
                if (in_array($field_name, array('id', 'slug', 'name', 'created_at', 'updated_at'))) {
                    continue;
                }
                $mysql_type = $this->map_field_type($field_type);
                $wpdb->query("ALTER TABLE `{$table_name}` MODIFY COLUMN `{$field_name}` {$mysql_type} COMMENT 'Type:{$field_type};'");
            }
        } else {
            // Update existing table - add missing columns and update comments
            $existing_columns = $this->get_table_columns($table_name);

            foreach ($fields as $field_name => $field_type) {
                // Skip reserved fields
                if (in_array($field_name, array('id', 'slug', 'name', 'created_at', 'updated_at'))) {
                    continue;
                }

                $mysql_type = $this->map_field_type($field_type);

                if (!isset($existing_columns[$field_name])) {
                    // Add new column with comment
                    $result = $wpdb->query("ALTER TABLE `{$table_name}` ADD COLUMN `{$field_name}` {$mysql_type} COMMENT 'Type:{$field_type};'");

                    if ($result === false) {
                        return new WP_Error('column_add_failed', "Failed to add column: {$field_name}");
                    }
                } else {
                    // Update existing column to add/update comment
                    $wpdb->query("ALTER TABLE `{$table_name}` MODIFY COLUMN `{$field_name}` {$mysql_type} COMMENT 'Type:{$field_type};'");
                }
            }
        }

        return true;
    }
    
    /**
     * Sync data table - main endpoint
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function sync_data_table($request) {
        global $wpdb;
        
        $slug = $request->get_param('slug');
        $name = $request->get_param('name');
        $structure = $request->get_param('structure');
        $rows = $request->get_param('rows');
        
        // Validate required fields
        if (empty($slug)) {
            return Texter_API_Response::validation_error('Slug is required');
        }
        
        // Sanitize slug
        $slug = sanitize_key($slug);
        if (empty($slug)) {
            return Texter_API_Response::validation_error('Invalid slug format');
        }
        
        $table_name = $this->get_table_name($slug);
        
        // Parse structure
        $fields = $this->parse_structure($structure);
        
        // Ensure table structure
        $result = $this->ensure_table_structure($table_name, $fields);
        if (is_wp_error($result)) {
            return Texter_API_Response::error($result->get_error_message());
        }
        
        // Process rows
        $inserted = 0;
        $updated = 0;
        
        if (!empty($rows) && is_array($rows)) {
            foreach ($rows as $index => $row) {
                if (!is_array($row)) {
                    return new WP_REST_Response(array(
                        'success' => false,
                        'error' => array(
                            'code' => 'invalid_row',
                            'message' => 'Invalid row format at row ' . ($index + 1),
                        ),
                        'index' => $index,
                        'inserted' => $inserted,
                        'updated' => $updated,
                    ), 400);
                }
                
                // Each row must have a slug
                if (empty($row['slug'])) {
                    return new WP_REST_Response(array(
                        'success' => false,
                        'error' => array(
                            'code' => 'missing_slug',
                            'message' => 'Row slug is required at row ' . ($index + 1),
                        ),
                        'index' => $index,
                        'inserted' => $inserted,
                        'updated' => $updated,
                    ), 400);
                }
                
                $row_slug = sanitize_key($row['slug']);
                $row_name = isset($row['name']) ? sanitize_text_field($row['name']) : '';
                
                // Check if row exists
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT id FROM `{$table_name}` WHERE slug = %s",
                    $row_slug
                ));
                
                // Prepare data for insert/update
                $data = array(
                    'slug' => $row_slug,
                    'name' => $row_name,
                );
                $formats = array('%s', '%s');
                
                // Add custom fields
                foreach ($fields as $field_name => $field_type) {
                    if (in_array($field_name, array('id', 'slug', 'name', 'created_at', 'updated_at'))) {
                        continue;
                    }
                    
                    if (isset($row[$field_name])) {
                        $value = $row[$field_name];
                        
                        // Handle JSON fields
                        if (in_array($field_type, array('array', 'object', 'json'))) {
                            $value = is_array($value) ? json_encode($value) : $value;
                            $formats[] = '%s';
                        } elseif (in_array($field_type, array('number', 'integer'))) {
                            $value = is_numeric($value) ? $value : null;
                            $formats[] = $field_type === 'integer' ? '%d' : '%f';
                        } elseif ($field_type === 'boolean') {
                            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                            $formats[] = '%d';
                        } else {
                            $value = sanitize_text_field((string)$value);
                            $formats[] = '%s';
                        }
                        
                        $data[$field_name] = $value;
                    }
                }
                
                if ($existing) {
                    // Update existing row
                    $update_result = $wpdb->update(
                        $table_name,
                        $data,
                        array('id' => $existing->id),
                        $formats,
                        array('%d')
                    );
                    
                    if ($update_result !== false) {
                        $updated++;
                    } else {
                        // Stop on first error and return it
                        return new WP_REST_Response(array(
                            'success' => false,
                            'error' => array(
                                'code' => 'update_failed',
                                'message' => 'Update failed for row ' . ($index + 1) . ' (slug: ' . $row_slug . '): ' . $wpdb->last_error,
                            ),
                            'index' => $index,
                            'slug' => $row_slug,
                            'inserted' => $inserted,
                            'updated' => $updated,
                        ), 400);
                    }
                } else {
                    // Insert new row
                    $insert_result = $wpdb->insert($table_name, $data, $formats);
                    
                    if ($insert_result !== false) {
                        $inserted++;
                    } else {
                        // Stop on first error and return it
                        return new WP_REST_Response(array(
                            'success' => false,
                            'error' => array(
                                'code' => 'insert_failed',
                                'message' => 'Insert failed for row ' . ($index + 1) . ' (slug: ' . $row_slug . '): ' . $wpdb->last_error,
                            ),
                            'index' => $index,
                            'slug' => $row_slug,
                            'inserted' => $inserted,
                            'updated' => $updated,
                        ), 400);
                    }
                }
            }
        }
        
        // Get total count
        $total_count = $wpdb->get_var("SELECT COUNT(*) FROM `{$table_name}`");
        
        return Texter_API_Response::success(array(
            'table' => $slug,
            'table_name' => $table_name,
            'name' => $name,
            'inserted' => $inserted,
            'updated' => $updated,
            'total' => (int)$total_count,
        ));
    }
    
    /**
     * List all data tables
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function list_data_tables($request) {
        global $wpdb;
        
        $pattern = $wpdb->prefix . $this->table_prefix . '%';
        $tables = $wpdb->get_results($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $pattern
        ), ARRAY_N);
        
        $result = array();
        foreach ($tables as $table) {
            $table_name = $table[0];
            $slug = str_replace($wpdb->prefix . $this->table_prefix, '', $table_name);
            
            $count = $wpdb->get_var("SELECT COUNT(*) FROM `{$table_name}`");
            $columns = $this->get_table_columns($table_name);
            
            $result[] = array(
                'slug' => $slug,
                'table_name' => $table_name,
                'rows_count' => (int)$count,
                'columns' => array_keys($columns),
            );
        }
        
        return Texter_API_Response::success(array(
            'tables' => $result,
        ));
    }
    
    /**
     * Get data table contents
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_data_table($request) {
        global $wpdb;
        
        $slug = sanitize_key($request->get_param('slug'));
        $table_name = $this->get_table_name($slug);
        
        if (!$this->table_exists($table_name)) {
            return Texter_API_Response::not_found('Table not found');
        }
        
        $page = max(1, intval($request->get_param('page') ?: 1));
        $limit = min(100, max(1, intval($request->get_param('limit') ?: 50)));
        $offset = ($page - 1) * $limit;
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM `{$table_name}`");
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table_name}` ORDER BY id ASC LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        );
        
        // Decode JSON fields
        $columns = $this->get_table_columns($table_name);
        foreach ($rows as &$row) {
            foreach ($row as $key => &$value) {
                if (isset($columns[$key]) && strpos(strtoupper($columns[$key]['type']), 'JSON') !== false) {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $value = $decoded;
                    }
                }
            }
        }
        
        return Texter_API_Response::success(array(
            'slug' => $slug,
            'table_name' => $table_name,
            'rows' => $rows,
            'pagination' => array(
                'page' => $page,
                'limit' => $limit,
                'total' => (int)$total,
                'total_pages' => ceil($total / $limit),
            ),
        ));
    }
    
    /**
     * Delete data table
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function delete_data_table($request) {
        global $wpdb;
        
        $slug = sanitize_key($request->get_param('slug'));
        $table_name = $this->get_table_name($slug);
        
        if (!$this->table_exists($table_name)) {
            return Texter_API_Response::not_found('Table not found');
        }
        
        $result = $wpdb->query("DROP TABLE `{$table_name}`");
        
        if ($result === false) {
            return Texter_API_Response::error('Failed to delete table');
        }
        
        return Texter_API_Response::success(array(
            'slug' => $slug,
            'deleted' => true,
        ));
    }
}
