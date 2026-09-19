<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Enterprise Hospital Management Information System (HMIS)
 * DataTables Server-Side Query Engine for PostgreSQL 15 & CodeIgniter 3
 *
 * Implements high-performance, injection-safe server-side pagination, ILIKE search,
 * and column sorting optimized for PostgreSQL 15.
 *
 * Compatible with PHP 8.4-FPM and DataTables 1.10+ / modern DataTables protocol.
 */
#[AllowDynamicProperties]
class Datatables {

    protected CI_Controller $ci;
    protected CI_DB_query_builder $db;

    protected string $table = '';
    protected string $select_columns = '*';
    protected array $joins = [];
    protected array $where_clauses = [];
    protected array $searchable_columns = [];
    protected array $orderable_columns = [];
    protected ?Closure $row_formatter = null;

    public function __construct() {
        $this->ci =& get_instance();
        $this->db =& $this->ci->db;
    }

    /**
     * Sets the target primary table or view.
     */
    public function from(string $table): self {
        $this->table = $table;
        return $this;
    }

    /**
     * Sets the selected columns.
     */
    public function select(string $columns): self {
        $this->select_columns = $columns;
        return $this;
    }

    /**
     * Adds an SQL JOIN clause.
     */
    public function join(string $table, string $condition, string $type = 'INNER'): self {
        $this->joins[] = [
            'table'     => $table,
            'condition' => $condition,
            'type'      => strtoupper($type)
        ];
        return $this;
    }

    /**
     * Adds static WHERE conditions to restrict scope (e.g. clinic_id, tenant_id).
     */
    public function where(string|array $key_or_array, mixed $value = null): self {
        if (is_array($key_or_array)) {
            foreach ($key_or_array as $k => $v) {
                $this->where_clauses[] = ['key' => $k, 'value' => $v];
            }
        } else {
            $this->where_clauses[] = ['key' => $key_or_array, 'value' => $value];
        }
        return $this;
    }

    /**
     * Registers columns eligible for global ILIKE search.
     */
    public function searchable(array $columns): self {
        $this->searchable_columns = $columns;
        return $this;
    }

    /**
     * Registers columns eligible for ordering, mapped by index or column name.
     */
    public function orderable(array $columns): self {
        $this->orderable_columns = array_values($columns);
        return $this;
    }

    /**
     * Registers a callback closure to format each output record before sending JSON.
     */
    public function format_rows(callable $callback): self {
        $this->row_formatter = $callback;
        return $this;
    }

    /**
     * Executes the query and generates DataTables-compliant array structure.
     */
    public function generate(): array {
        // 1. Read DataTables client parameters (supports both GET and POST)
        $draw    = (int)($this->ci->input->get_post('draw', TRUE) ?: 1);
        $start   = max(0, (int)$this->ci->input->get_post('start', TRUE));
        $length  = (int)($this->ci->input->get_post('length', TRUE) ?: 10);
        $search  = $this->ci->input->get_post('search', TRUE);
        $order   = $this->ci->input->get_post('order', TRUE);

        $search_value = is_array($search) && isset($search['value']) ? trim((string)$search['value']) : '';
        $length = ($length < 0 || $length > 100) ? 10 : $length; // Bound page size

        // 2. Compute recordsTotal (total unfiltered records for this view/tenant)
        $this->apply_base_scope();
        $records_total = $this->db->count_all_results('', FALSE);
        $this->db->reset_query();

        // 3. Compute recordsFiltered (with search filter applied)
        $this->apply_base_scope();
        $this->apply_search_filter($search_value);
        $records_filtered = $this->db->count_all_results('', FALSE);
        $this->db->reset_query();

        // 4. Fetch paginated, ordered dataset
        $this->apply_base_scope();
        $this->apply_search_filter($search_value);
        $this->apply_ordering($order);

        $this->db->select($this->select_columns);
        $this->db->limit($length, $start);
        $query = $this->db->get();

        $rows = $query->result_array();

        // 5. Apply row transformation closure if registered
        if (is_callable($this->row_formatter)) {
            $formatted_rows = [];
            foreach ($rows as $index => $row) {
                $formatted_rows[] = ($this->row_formatter)($row, $index);
            }
            $rows = $formatted_rows;
        }

        return [
            'draw'            => $draw,
            'recordsTotal'    => (int)$records_total,
            'recordsFiltered' => (int)$records_filtered,
            'data'            => $rows
        ];
    }

    /**
     * Applies table, joins, and static WHERE clauses to active query builder.
     */
    protected function apply_base_scope(): void {
        $this->db->from($this->table);

        foreach ($this->joins as $j) {
            $this->db->join($j['table'], $j['condition'], $j['type']);
        }

        foreach ($this->where_clauses as $w) {
            if ($w['value'] === null) {
                $this->db->where($w['key']);
            } else {
                $this->db->where($w['key'], $w['value']);
            }
        }
    }

    /**
     * Applies PostgreSQL 15 ILIKE filter across searchable columns with type casting.
     */
    protected function apply_search_filter(string $search_value): void {
        if ($search_value === '' || empty($this->searchable_columns)) {
            return;
        }

        $escaped = $this->db->escape_like_str($search_value);
        $like_term = "'%{$escaped}%'";

        $conditions = [];
        foreach ($this->searchable_columns as $column) {
            // PostgreSQL optimization: cast UUID, INT, or TIMESTAMPTZ columns to TEXT for safe ILIKE
            $conditions[] = "CAST({$column} AS TEXT) ILIKE {$like_term}";
        }

        if (!empty($conditions)) {
            $this->db->where('(' . implode(' OR ', $conditions) . ')', NULL, FALSE);
        }
    }

    /**
     * Applies sanitized column ordering.
     */
    protected function apply_ordering(mixed $order): void {
        if (!is_array($order) || empty($order) || empty($this->orderable_columns)) {
            // Fallback default order
            $this->db->order_by($this->orderable_columns[0] ?? 1, 'DESC');
            return;
        }

        foreach ($order as $item) {
            if (!isset($item['column'], $item['dir'])) {
                continue;
            }

            $col_idx = (int)$item['column'];
            $direction = strtolower((string)$item['dir']) === 'asc' ? 'ASC' : 'DESC';

            // Validate against whitelist of orderable columns
            if (isset($this->orderable_columns[$col_idx])) {
                $column_name = $this->orderable_columns[$col_idx];
                $this->db->order_by($column_name, $direction);
            }
        }
    }
}
