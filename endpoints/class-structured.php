<?php
/**
 * Structured data endpoint for Texter API
 * 
 * Manages custom database tables for structured data:
 * - Tables are named {wp_prefix}data_{name}
 * - Supports creating, updating, deleting table structures
 * - Supports uploading and retrieving data
 */

if (!defined('ABSPATH')) {
    exit;
}

class Texter_API_Endpoint_Structured {
    
    /**
     * Table name prefix (after wp_prefix)
     */
    const TABLE_PREFIX = 'data_';
    
    /**
     * Register routes
     *
     * @param string $namespace
     */
    public function register_routes($namespace) {
        // Type management (table structure)
        register_rest_route($namespace, '/structured/types', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'get_types'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
            ),
            array(
                'methods' => 'POST',
                'callback' => array($this, 'create_type'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
            ),
        ));
        
        register_rest_route($namespace, '/structured/types/(?P<name>[a-z0-9_]+)', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'get_type'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
            ),
            array(
                'methods' => 'PUT',
                'callback' => array($this, 'update_type'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
            ),
            array(
                'methods' => 'DELETE',
                'callback' => array($this, 'delete_type'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
            ),
        ));
        
        // Data management (table rows)
        register_rest_route($namespace, '/structured/data/(?P<name>[a-z0-9_]+)', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'get_data'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
            ),
            array(
                'methods' => 'POST',
                'callback' => array($this, 'upload_data'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
            ),
            array(
                'methods' => 'DELETE',
                'callback' => array($this, 'delete_data'),
                'permission_callback' => array('Texter_API_Auth', 'check_permission'),
            ),
        ));
    }
    
    /**
     * Get full table name with prefix
     *
     * @param string $name
     * @return string
     */
    private function get_table_name($name) {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_PREFIX . $name;
    }
    
    /**
     * Check if table exists and belongs to our data_ prefix
     *
     * @param string $name
     * @return bool
     */
    private function table_exists($name) {
        global $wpdb;
        $table_name = $this->get_table_name($name);
        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
    }
    
    /**
     * Validate type name (only lowercase letters, numbers, underscores)
     *
     * @param string $name
     * @return bool
     */
    private function validate_name($name) {
        return preg_match('/^[a-z][a-z0-9_]{0,49}$/', $name);
    }
    
    /**
     * Map field type to MySQL column type
     *
     * @param array $field
     * @return string
     */
    private function get_mysql_type($field) {
        $type = strtolower($field['type']);
        $length = isset($field['length']) ? intval($field['length']) : null;
        
        switch ($type) {
            case 'int':
            case 'integer':
                return 'INT';
            case 'bigint':
                return 'BIGINT';
            case 'smallint':
                return 'SMALLINT';
            case 'tinyint':
                return 'TINYINT';
            case 'float':
                return 'FLOAT';
            case 'double':
                return 'DOUBLE';
            case 'decimal':
                $precision = isset($field['precision']) ? intval($field['precision']) : 10;
                $scale = isset($field['scale']) ? intval($field['scale']) : 2;
                return "DECIMAL($precision, $scale)";
            case 'bool':
            case 'boolean':
                return 'TINYINT(1)';
            case 'date':
                return 'DATE';
            case 'datetime':
                return 'DATETIME';
            case 'timestamp':
                return 'TIMESTAMP';
            case 'time':
                return 'TIME';
            case 'text':
                return 'TEXT';
            case 'mediumtext':
                return 'MEDIUMTEXT';
            case 'longtext':
                return 'LONGTEXT';
            case 'json':
                return 'JSON';
            case 'string':
            case 'varchar':
            default:
                $len = $length ?: 255;
                return "VARCHAR($len)";
        }
    }
    
    /**
     * Get all structured data types (tables)
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_types($request) {
        global $wpdb;
        
        $prefix = $wpdb->prefix . self::TABLE_PREFIX;
        $tables = $wpdb->get_results(
            $wpdb->prepare("SHOW TABLES LIKE %s", $prefix . '%'),
            ARRAY_N
        );
        
        $types = array();
        foreach ($tables as $table) {
            $table_name = $table[0];
            $name = str_replace($prefix, '', $table_name);
            
            // Get table structure
            $columns = $wpdb->get_results("DESCRIBE `$table_name`", ARRAY_A);
            $row_count = $wpdb->get_var("SELECT COUNT(*) FROM `$table_name`");
            
            $fields = array();
            foreach ($columns as $column) {
                if ($column['Field'] === 'id') continue; // Skip auto-increment id
                
                $fields[] = array(
                    'name' => $column['Field'],
                    'type' => $column['Type'],
                    'nullable' => $column['Null'] === 'YES',
                    'default' => $column['Default'],
                );
            }
            
            $types[] = array(
                'name' => $name,
                'table' => $table_name,
                'fields' => $fields,
                'row_count' => intval($row_count),
            );
        }
        
        return Texter_API_Response::success(array(
            'types' => $types,
        ));
    }
    
    /**
     * Get single type structure
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_type($request) {
        global $wpdb;
        
        $name = $request->get_param('name');
        
        if (!$this->validate_name($name)) {
            return Texter_API_Response::validation_error('Invalid type name. Use lowercase letters, numbers, and underscores only.');
        }
        
        if (!$this->table_exists($name)) {
            return Texter_API_Response::not_found('Type not found: ' . $name);
        }
        
        $table_name = $this->get_table_name($name);
        $columns = $wpdb->get_results("DESCRIBE `$table_name`", ARRAY_A);
        $row_count = $wpdb->get_var("SELECT COUNT(*) FROM `$table_name`");
        
        $fields = array();
        foreach ($columns as $column) {
            if ($column['Field'] === 'id') continue;
            
            $fields[] = array(
                'name' => $column['Field'],
                'type' => $column['Type'],
                'nullable' => $column['Null'] === 'YES',
                'default' => $column['Default'],
                'key' => $column['Key'],
            );
        }
        
        return Texter_API_Response::success(array(
            'name' => $name,
            'table' => $table_name,
            'fields' => $fields,
            'row_count' => intval($row_count),
        ));
    }
    
    /**
     * Create a new structured data type (table)
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function create_type($request) {
        global $wpdb;
        
        $name = $request->get_param('name');
        $fields = $request->get_param('fields');
        
        // Validate name
        if (empty($name)) {
            return Texter_API_Response::validation_error('Type name is required');
        }
        
        if (!$this->validate_name($name)) {
            return Texter_API_Response::validation_error('Invalid type name. Must start with a letter and contain only lowercase letters, numbers, and underscores (max 50 chars).');
        }
        
        // Check if table already exists
        if ($this->table_exists($name)) {
            return Texter_API_Response::error('Type already exists: ' . $name, 409);
        }
        
        // Validate fields
        if (empty($fields) || !is_array($fields)) {
            return Texter_API_Response::validation_error('Fields array is required');
        }
        
        // Build CREATE TABLE query
        $table_name = $this->get_table_name($name);
        $charset_collate = $wpdb->get_charset_collate();
        
        $column_defs = array();
        $column_defs[] = 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT';
        
        foreach ($fields as $field) {
            if (empty($field['name'])) {
                return Texter_API_Response::validation_error('Field name is required');
            }
            
            $field_name = sanitize_key($field['name']);
            if ($field_name === 'id') {
                continue; // Skip, we already have id
            }
            
            $mysql_type = $this->get_mysql_type($field);
            $nullable = isset($field['nullable']) && $field['nullable'] ? 'NULL' : 'NOT NULL';
            $default = '';
            
            if (isset($field['default'])) {
                if ($field['default'] === null) {
                    $default = 'DEFAULT NULL';
                } elseif (is_numeric($field['default'])) {
                    $default = 'DEFAULT ' . $field['default'];
                } else {
                    $default = "DEFAULT '" . esc_sql($field['default']) . "'";
                }
            }
            
            $column_defs[] = "`$field_name` $mysql_type $nullable $default";
        }
        
        $column_defs[] = 'created_at DATETIME DEFAULT CURRENT_TIMESTAMP';
        $column_defs[] = 'updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';
        $column_defs[] = 'PRIMARY KEY (id)';
        
        // Add indexes for fields marked as indexed
        foreach ($fields as $field) {
            if (!empty($field['index']) && !empty($field['name'])) {
                $field_name = sanitize_key($field['name']);
                $column_defs[] = "INDEX idx_$field_name (`$field_name`)";
            }
            if (!empty($field['unique']) && !empty($field['name'])) {
                $field_name = sanitize_key($field['name']);
                $column_defs[] = "UNIQUE KEY uk_$field_name (`$field_name`)";
            }
        }
        
        $sql = "CREATE TABLE `$table_name` (\n" . implode(",\n", $column_defs) . "\n) $charset_collate";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $result = $wpdb->query($sql);
        
        if ($result === false) {
            return Texter_API_Response::error('Failed to create table: ' . $wpdb->last_error);
        }
        
        return Texter_API_Response::success(array(
            'name' => $name,
            'table' => $table_name,
            'created' => true,
        ), 201);
    }
    
    /**
     * Update structured data type (alter table)
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function update_type($request) {
        global $wpdb;
        
        $name = $request->get_param('name');
        $fields = $request->get_param('fields');
        $drop_fields = $request->get_param('drop_fields');
        
        if (!$this->validate_name($name)) {
            return Texter_API_Response::validation_error('Invalid type name');
        }
        
        if (!$this->table_exists($name)) {
            return Texter_API_Response::not_found('Type not found: ' . $name);
        }
        
        $table_name = $this->get_table_name($name);
        $alterations = array();
        
        // Get existing columns
        $existing_columns = array();
        $columns = $wpdb->get_results("DESCRIBE `$table_name`", ARRAY_A);
        foreach ($columns as $column) {
            $existing_columns[$column['Field']] = $column;
        }
        
        // Add or modify fields
        if (!empty($fields) && is_array($fields)) {
            foreach ($fields as $field) {
                if (empty($field['name'])) continue;
                
                $field_name = sanitize_key($field['name']);
                
                // Skip protected columns
                if (in_array($field_name, array('id', 'created_at', 'updated_at'))) {
                    continue;
                }
                
                $mysql_type = $this->get_mysql_type($field);
                $nullable = isset($field['nullable']) && $field['nullable'] ? 'NULL' : 'NOT NULL';
                $default = '';
                
                if (isset($field['default'])) {
                    if ($field['default'] === null) {
                        $default = 'DEFAULT NULL';
                    } elseif (is_numeric($field['default'])) {
                        $default = 'DEFAULT ' . $field['default'];
                    } else {
                        $default = "DEFAULT '" . esc_sql($field['default']) . "'";
                    }
                }
                
                if (isset($existing_columns[$field_name])) {
                    // Modify existing column
                    $alterations[] = "MODIFY COLUMN `$field_name` $mysql_type $nullable $default";
                } else {
                    // Add new column
                    $alterations[] = "ADD COLUMN `$field_name` $mysql_type $nullable $default";
                    
                    // Add index if requested
                    if (!empty($field['index'])) {
                        $alterations[] = "ADD INDEX idx_$field_name (`$field_name`)";
                    }
                    if (!empty($field['unique'])) {
                        $alterations[] = "ADD UNIQUE KEY uk_$field_name (`$field_name`)";
                    }
                }
            }
        }
        
        // Drop fields
        if (!empty($drop_fields) && is_array($drop_fields)) {
            foreach ($drop_fields as $field_name) {
                $field_name = sanitize_key($field_name);
                
                // Skip protected columns
                if (in_array($field_name, array('id', 'created_at', 'updated_at'))) {
                    continue;
                }
                
                if (isset($existing_columns[$field_name])) {
                    $alterations[] = "DROP COLUMN `$field_name`";
                }
            }
        }
        
        if (empty($alterations)) {
            return Texter_API_Response::success(array(
                'name' => $name,
                'table' => $table_name,
                'updated' => false,
                'message' => 'No changes to apply',
            ));
        }
        
        $sql = "ALTER TABLE `$table_name` " . implode(", ", $alterations);
        $result = $wpdb->query($sql);
        
        if ($result === false) {
            return Texter_API_Response::error('Failed to update table: ' . $wpdb->last_error);
        }
        
        return Texter_API_Response::success(array(
            'name' => $name,
            'table' => $table_name,
            'updated' => true,
            'alterations' => count($alterations),
        ));
    }
    
    /**
     * Delete structured data type (drop table)
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function delete_type($request) {
        global $wpdb;
        
        $name = $request->get_param('name');
        
        if (!$this->validate_name($name)) {
            return Texter_API_Response::validation_error('Invalid type name');
        }
        
        if (!$this->table_exists($name)) {
            return Texter_API_Response::not_found('Type not found: ' . $name);
        }
        
        $table_name = $this->get_table_name($name);
        
        // Safety check - only allow deleting tables with our prefix
        $expected_prefix = $wpdb->prefix . self::TABLE_PREFIX;
        if (strpos($table_name, $expected_prefix) !== 0) {
            return Texter_API_Response::error('Cannot delete this table');
        }
        
        $result = $wpdb->query("DROP TABLE `$table_name`");
        
        if ($result === false) {
            return Texter_API_Response::error('Failed to delete table: ' . $wpdb->last_error);
        }
        
        return Texter_API_Response::success(array(
            'name' => $name,
            'deleted' => true,
        ));
    }
    
    /**
     * Get data from structured data table
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_data($request) {
        global $wpdb;
        
        $name = $request->get_param('name');
        
        if (!$this->validate_name($name)) {
            return Texter_API_Response::validation_error('Invalid type name');
        }
        
        if (!$this->table_exists($name)) {
            return Texter_API_Response::not_found('Type not found: ' . $name);
        }
        
        $table_name = $this->get_table_name($name);
        
        // Pagination
        $page = max(1, intval($request->get_param('page') ?: 1));
        $per_page = min(1000, max(1, intval($request->get_param('per_page') ?: 100)));
        $offset = ($page - 1) * $per_page;
        
        // Sorting
        $order_by = $request->get_param('order_by') ?: 'id';
        $order = strtoupper($request->get_param('order') ?: 'ASC');
        if (!in_array($order, array('ASC', 'DESC'))) {
            $order = 'ASC';
        }
        
        // Validate order_by column exists
        $columns = $wpdb->get_col("DESCRIBE `$table_name`", 0);
        if (!in_array($order_by, $columns)) {
            $order_by = 'id';
        }
        
        // Get total count
        $total = intval($wpdb->get_var("SELECT COUNT(*) FROM `$table_name`"));
        
        // Build and execute query - use sprintf for ORDER BY since prepare() doesn't handle identifiers
        $sql = $wpdb->prepare(
            "SELECT * FROM `$table_name` ORDER BY `%s` " . $order . " LIMIT %d OFFSET %d",
            $order_by,
            $per_page,
            $offset
        );
        
        // Fix: WordPress prepare() escapes %s with quotes, but we need backticks for column names
        // So we build the query manually with sanitized values
        $sql = sprintf(
            "SELECT * FROM `%s` ORDER BY `%s` %s LIMIT %d OFFSET %d",
            $table_name,
            esc_sql($order_by),
            $order,
            intval($per_page),
            intval($offset)
        );
        
        $rows = $wpdb->get_results($sql, ARRAY_A);
        
        // Check for database errors
        if ($wpdb->last_error) {
            return Texter_API_Response::error('Database error: ' . $wpdb->last_error);
        }
        
        return Texter_API_Response::success(array(
            'type' => $name,
            'data' => is_array($rows) ? $rows : array(),
            'columns' => $columns,
            'pagination' => array(
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => ceil($total / $per_page),
            ),
        ));
    }
    
    /**
     * Upload/upsert data to structured data table
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function upload_data($request) {
        global $wpdb;
        
        $name = $request->get_param('name');
        $rows = $request->get_param('rows');
        $mode = $request->get_param('mode') ?: 'upsert'; // 'insert', 'update', 'upsert', 'replace'
        
        if (!$this->validate_name($name)) {
            return Texter_API_Response::validation_error('Invalid type name');
        }
        
        if (!$this->table_exists($name)) {
            return Texter_API_Response::not_found('Type not found: ' . $name);
        }
        
        if (empty($rows) || !is_array($rows)) {
            return Texter_API_Response::validation_error('Rows array is required');
        }
        
        $table_name = $this->get_table_name($name);
        
        // Get valid columns
        $valid_columns = $wpdb->get_col("DESCRIBE `$table_name`", 0);
        $valid_columns = array_flip($valid_columns);
        unset($valid_columns['created_at']); // Don't allow manual setting
        
        $inserted = 0;
        $updated = 0;
        $errors = array();
        
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                $errors[] = "Row $index: Invalid data format";
                continue;
            }
            
            // Filter to valid columns only
            $data = array();
            foreach ($row as $key => $value) {
                if (isset($valid_columns[$key])) {
                    $data[$key] = $value;
                }
            }
            
            if (empty($data)) {
                $errors[] = "Row $index: No valid fields";
                continue;
            }
            
            $has_id = isset($data['id']) && !empty($data['id']);
            
            if ($mode === 'insert' || (!$has_id && $mode !== 'update')) {
                // Insert new row
                unset($data['id']); // Remove id for insert
                $result = $wpdb->insert($table_name, $data);
                if ($result !== false) {
                    $inserted++;
                } else {
                    $errors[] = "Row $index: " . $wpdb->last_error;
                }
            } elseif ($mode === 'update' && $has_id) {
                // Update existing row
                $id = intval($data['id']);
                unset($data['id']);
                unset($data['updated_at']); // Let MySQL handle this
                
                $result = $wpdb->update($table_name, $data, array('id' => $id));
                if ($result !== false) {
                    $updated++;
                } else {
                    $errors[] = "Row $index: " . $wpdb->last_error;
                }
            } elseif ($mode === 'upsert' && $has_id) {
                // Check if exists, then insert or update
                $id = intval($data['id']);
                $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM `$table_name` WHERE id = %d", $id));
                
                if ($exists) {
                    unset($data['id']);
                    unset($data['updated_at']);
                    $result = $wpdb->update($table_name, $data, array('id' => $id));
                    if ($result !== false) {
                        $updated++;
                    } else {
                        $errors[] = "Row $index: " . $wpdb->last_error;
                    }
                } else {
                    $result = $wpdb->insert($table_name, $data);
                    if ($result !== false) {
                        $inserted++;
                    } else {
                        $errors[] = "Row $index: " . $wpdb->last_error;
                    }
                }
            } elseif ($mode === 'replace') {
                // Use REPLACE INTO
                $columns = array_keys($data);
                $placeholders = array_fill(0, count($data), '%s');
                $values = array_values($data);
                
                $sql = "REPLACE INTO `$table_name` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")";
                $result = $wpdb->query($wpdb->prepare($sql, $values));
                
                if ($result !== false) {
                    $inserted++; // REPLACE counts as insert
                } else {
                    $errors[] = "Row $index: " . $wpdb->last_error;
                }
            }
        }
        
        return Texter_API_Response::success(array(
            'type' => $name,
            'inserted' => $inserted,
            'updated' => $updated,
            'errors' => $errors,
            'total_processed' => count($rows),
        ));
    }
    
    /**
     * Delete data from structured data table
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function delete_data($request) {
        global $wpdb;
        
        $name = $request->get_param('name');
        $ids = $request->get_param('ids');
        $truncate = $request->get_param('truncate');
        
        if (!$this->validate_name($name)) {
            return Texter_API_Response::validation_error('Invalid type name');
        }
        
        if (!$this->table_exists($name)) {
            return Texter_API_Response::not_found('Type not found: ' . $name);
        }
        
        $table_name = $this->get_table_name($name);
        
        if ($truncate === true) {
            // Delete all data
            $result = $wpdb->query("TRUNCATE TABLE `$table_name`");
            
            if ($result === false) {
                return Texter_API_Response::error('Failed to truncate table: ' . $wpdb->last_error);
            }
            
            return Texter_API_Response::success(array(
                'type' => $name,
                'truncated' => true,
            ));
        }
        
        if (empty($ids) || !is_array($ids)) {
            return Texter_API_Response::validation_error('IDs array is required (or set truncate=true to delete all)');
        }
        
        // Sanitize IDs
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, function($id) { return $id > 0; });
        
        if (empty($ids)) {
            return Texter_API_Response::validation_error('No valid IDs provided');
        }
        
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM `$table_name` WHERE id IN ($placeholders)",
            $ids
        ));
        
        if ($result === false) {
            return Texter_API_Response::error('Failed to delete rows: ' . $wpdb->last_error);
        }
        
        return Texter_API_Response::success(array(
            'type' => $name,
            'deleted' => $result,
        ));
    }
}
