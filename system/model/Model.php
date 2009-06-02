<?php

/**
 * @package 	Aspen_Framework
 * @subpackage 	System
 * @author 		Michael Botsko
 * @copyright 	2009 Trellis Development, LLC
 * @since 		1.0
 */

/**
 * @abstract This class manages our mysql sql query generation
 * @package Aspen_Framework
 */
class Model {
	
	/**
	 * @var object Holds our original application
	 * @access private
	 */
	protected $APP;
	
	/**
	 * @var array Holds an array of calculations we need to perform on the results
	 * @access private
	 */
	private $calcs = false;
	
	/**
	 * @var array $errors Holds an array of field validation errors
	 * @access private
	 */
	private $errors = array();

	/**
	 * @abstract Flags a validation error
	 * @var boolean $error
	 * @access private
	 */
	private $error = false;

	/**
	 * @var integer Current page = total results divided by per_page
	 */
	private $current_page = false;
	
	/**
	 * @var array Holds an array of security rules to apply to each field.
	 * @access private
	 */
	private $field_security_rules = array();
	
	/**
	 * @var string Holds the last executed query
	 * @access private
	 */
	private $last_query;
	
	/**
	 * @var boolean Toggles the pagination features
	 * @access private
	 */
	private $paginate = false;
	
	/**
	 * @var string undocumented class variable
	 * @access private
	 */
	private $parenth_start = false;
	
	/**
	 * @var integer Records per page for pagination
	 */
	private $per_page = false;
	
	/**
	 * @var array Holds the type of query we're running, so we know what to return
	 * @access private
	 */
	private $query_type;

	/**
	 * @var object Holds the schema for the currently selected database
	 * @access private
	 */
	private $schema;

	/**
	 * @var string Holds our current SQL query
	 * @access private
	 */
	private $sql;

	/**
	 * @var string Identifies our currently select table
	 * @access private
	 */
	private $table;

	
	/**
	 * @abstract Contrucor, obtains an instance of the original app
	 * @return Model
	 * @access private
	 */
	public function __construct($table = false){
		$this->APP = get_instance();
		if($table){ $this->openTable($table); }
	}
	
	
//+-----------------------------------------------------------------------+
//| OPEN / SET / GET FUNCTIONS
//+-----------------------------------------------------------------------+

	
	/**
	 * @abstract Returns a model object or its child.
	 * @param string $table
	 * @return object
	 * @access public
	 */
	 final public function open($table){

	 	if($table){
	 	
		 	$class = 'Model';
			
			// identify available model extensions
			$exts = $this->APP->config('models');
			if(is_array($exts)){
				if(array_key_exists($table, $exts)){
					$class = ucwords($table).'Model';
				}
			}
			
			if(class_exists($class)){
				return new $class($table);
			} else {
				$this->APP->error->raise(2, 'Failed loading model class: ' . $class, __FILE__, __LINE__);
				return new Model($table);
			}
			
		}
			
		return false;
		
	}
	
	
	/**
	 * @abstract Returns a model object or its child, and begins a basic SELECT statement.
	 * @param string $table
	 * @return object
	 * @access public
	 */
	 final public function openAndSelect($table){
	 	$model = $this->open($table);
	 	if(is_object($model)){
	 		$model->select();
	 	}
		return $model;
	}
	

	/**
	 * @abstract Sets the current table and loads the table schema
	 * @param string $table
	 * @access private
	 * @return mixed
	 */
	private function openTable($table = false){
		$this->table = $table;
		$this->generateSchema();
		
		if(!is_array($this->schema)){
			$this->APP->error->raise(1, 'Failed generating schema for ' . $this->table . ' table.', __FILE__, __LINE__);
		}
	}
	
	
	/**
	 * @abstract Validates data is appropriate for the table before saving.
	 * @return boolean
	 * @access public
	 */
	public function validate($fields = false){

		$clean = false;
		
		// $fields must be an array or insert/update may not happen
		if(is_array($fields)){
			
			// make an inspekt cage so we can verify data
			$clean 	= Inspekt_Cage::Factory($fields);
			$schema = $this->getSchema();
			
			foreach($schema as $column){
				
				// if it's set, and a value is present, we must validate that
				// value against the database.
				// whether or not the value is present is up to the model extension, not this
				if($clean->isSetAndNotEmpty( $column->name )){
					
	
					/**
					 * Validate INTEGERs along with unsigned and maxlengths
					 */
					if(in_array($column->type, $this->APP->config('mysql_field_group_int'))){
						if(!$clean->getInt( $column->name )){
							$this->addError($column->name, 'Invalid db value. ' . $column->name . ' should be an integer.');
						} else {
							if($column->unsigned && !$clean->testGreaterThan( $column->name, -1 )){
								$this->addError($column->name, 'Invalid db value. ' . $column->name . ' may not be negative.');
							}
						}
					}
					
					
					/**
					 * Validate FLOATs along with unsigned and maxlengths
					 */
					if(in_array($column->type, $this->APP->config('mysql_field_group_dec'))){
						if(!$clean->getFloat( $column->name )){
							$this->addError($column->name, 'Invalid db value. ' . $column->name . ' should be a decimal or float.');
						} else {
							if($column->unsigned && !$clean->testGreaterThan( $column->name, -1 )){
								$this->addError($column->name, 'Invalid db value. ' . $column->name . ' may not be negative.');
							}
						}
					}
					
					
					/**
					 * Validate DATEs
					 */
					if(in_array($column->type, $this->APP->config('mysql_field_group_date'))){
						//if(!$clean->testDate( $date )){
							//$this->addError($column->name, 'Invalid db value. ' . $column->name . ' must be a date.');
						//}
					}
					
				
					/**
					 * Validate ENUMs
					 */
					if($column->type == 'enum'){
						if(!in_array($clean->getRaw( $column->name ), $column->enums)){
							$this->addError($column->name, 'Invalid db value. ' . $column->name . ' is not in list of acceptable values.');
						}
					}
					
					
					/**
					 * Rules to apply to all
					 */
					
					// maxlength
					if($column->max_length > 0 && strlen($clean->getRaw($column->name)) > $column->max_length){
						$this->addError($column->name, 'Invalid db value. ' . $column->name . ' exceeds maxlength.');
					}
				}
			}
		}
		
		return $clean;
		
	}
	
	
	/**
	 * @abstract Loads the current table schema.
	 * @access private
	 * @return mixed
	 */
	private function generateSchema(){
		return $this->schema = $this->APP->db->MetaColumns($this->table, false);
	}
	
	
	/**
	 * @abstract Returns raw schema for the current table
	 * @return array
	 * @access public
	 */
	final public function getSchema(){
		return $this->schema;
	}
	
	
	/**
	 * @abstract Verifies that a field is present in the current db schema
	 * @param string $field
	 * @access public
	 */
	final public function inSchema($field){
		return array_key_exists(strtoupper($field), $this->schema);
	}
	
	
	/**
	 * @abstract Returns the field marked as primary key for current table
	 * @return mixed
	 */
	final public function getPrimaryKey(){

		$schema = $this->getSchema();

		if(is_array($schema)){
			foreach($schema as $field){
				if($field->primary_key){
					return $field->name;
				}
			}
		}
		return false;
	}
	
	
	/**
	 * @abstract Sets the pagination toggle to true
	 * @access public
	 */
	public function enablePagination(){
		$this->paginate = true;
	}
	
	
	/**
	 * @abstract Returns the table status info
	 * @param string $table
	 * @return array
	 */
	public function showStatus($table = false){
		
		$table = $table ? $table : $this->table;

		if($table){
			$records = $this->query(sprintf('SHOW TABLE STATUS LIKE "%s"', $table));
			if($records->RecordCount()){
				while($record = $records->FetchRow()){
					return $record;
				}
			}
		}
		return false;
	}
	
	
	/**
	 * @abstract Returns the last run query
	 * @return string
	 * @access public
	 */
	public function getLastQuery(){
		return $this->last_query;
	}
	
	
	/**
	 * @abstract Returns the last run query - aliases getLastQuery
	 * @return string
	 * @access public
	 */
	public function lq(){
		return $this->getLastQuery();
	}
	
	/**
	 * @abstract Returns the query currently being built
	 * @return string
	 * @access public
	 */
	public function getBuildQuery(){
		return $this->writeSql();
	}



//+-----------------------------------------------------------------------+
//| SECURITY RULES
//+-----------------------------------------------------------------------+

	
	/**
	 * @abstract Sets a security rule for data coming into a specific field
	 * @param string $field
	 * @param string $key
	 * @param string $value
	 * @access public
	 */
	final public function setSecurityRule($field, $key, $value){
		if($this->inSchema($field_name)){
			$this->field_security_rules[$field][$key] = $value;
		}
	}
	
	
	/**
	 * @abstract Returns the security rule for a field and key
	 * @param string $field
	 * @param string $key
	 * @return mixed
	 */
	final private function getSecurityRule($field, $key){
		
		$rule_result = false;
		
		if($this->inSchema($field)){
			if(isset($this->field_security_rules[$field][$key])){
				$rule_result = $this->field_security_rules[$field][$key];
			}
		}
		
		return $rule_result;
	}
	
	
//+-----------------------------------------------------------------------+
//| SELECT GENERATING FUNCTIONS
//+-----------------------------------------------------------------------+
	
	
	/**
	 * @abstract Adds a new select statement to our query
	 * @param array $fields
	 * @param boolean $distinct
	 * @access public
	 */
	public function select($fields = false, $distinct = false){

		// begin the select, append SQL_CALC_FOUND_ROWS is pagination is enabled
		$this->sql['SELECT'] = $this->paginate ? 'SELECT SQL_CALC_FOUND_ROWS' : 'SELECT';
		
		// determine fields if any set
		$fields = is_array($fields) ? $fields : array('*');
		$official_fields = array();
		foreach($fields as $field){
			$official_fields[] = sprintf('%s.%s', $this->table, $field);
		}
		
		// append fields, append distinct if enabled
		$this->sql['FIELDS'] = ($distinct ? ' DISTINCT ' : '') . implode(', ', $official_fields);
		
		// set the from for our current table
		$this->sql['FROM'] = sprintf('FROM %s', $this->table);

	}


	/**
	 * @abstract Adds an additional select field
	 * @param string $field
	 * @access public
	 */
	public function addSelectField($field){
		$this->sql['FIELDS'] .= sprintf(', %s', $field);
	}


	/**
	 * @abstract Generates a left join
	 * @param string $table
	 * @param string $key
	 * @param string $foreign_key
	 * @param array $fields Fields you want to return
	 */
	public function leftJoin($table, $key, $foreign_key, $fields = false, $from_table = false){
	
		$from_table = $from_table ? $from_table : $this->table;

		// if the user has included an as translation, use it
		if(strpos($table, " as ") > 0){

			$table_values = explode(" as ", $table);
			$table = $table_values[0];
			$as_table = $table_values[1];
			$as = ' as ' . $as_table;

		} else {
			$as = false;
			$as_table = $table;
		}

		// append the left join statement itself
		$this->sql['LEFT_JOIN'][] = sprintf('LEFT JOIN %s ON %s = %s.%s', $table . $as, $as_table.'.'.$key, $from_table, $foreign_key);

		// append the fields we've selected
		if($fields){
			foreach($fields as $field){
				if(strpos($field, "SUM") === false){
					$this->sql['FIELDS'] .= sprintf(', %s.%s', $as_table, $field);
				} else {
					$this->sql['FIELDS'] .= sprintf(', %s', $field);
				}
			}
		}
	}

	
//+-----------------------------------------------------------------------+
//| CONDITION GENERATING FUNCTIONS
//+-----------------------------------------------------------------------+

	
	/**
	 * @abstract undocumented function
	 * @return void
	 * @access private
	 **/
	public function parenthStart(){
		$this->parenth_start = true;
	}
	
	
	/**
	 * @abstract undocumented function
	 * @return void
	 * @access private
	 **/
	public function parenthEnd(){
		$this->sql['WHERE'][ (count($this->sql['WHERE'])-1) ] .= ')';
	}
	
	
	/**
	 * @abstract Adds a standard where condition
	 * @param string $field
	 * @param mixed $value
	 * @param string $match
	 * @access public
	 */
	public function where($field, $value, $match = 'AND'){
		
		$match = $this->parenth_start ? $match.' (' : $match;
		$this->parenth_start = false;
		
		$this->sql['WHERE'][] = sprintf('%s %s = "%s"',
											(isset($this->sql['WHERE']) ? $match : 'WHERE'),
											$field,
											$this->APP->security->dbescape($value, $this->getSecurityRule($field, 'allow_html'))
										);
	}
	

	/**
	 * @abstract Adds a standard where not condition
	 * @param string $field
	 * @param mixed $value
	 * @param string $match
	 * @access public
	 */
	public function whereNot($field, $value, $match = 'AND'){
		$this->sql['WHERE'][] = sprintf('%s %s != "%s"', (isset($this->sql['WHERE']) ? $match : 'WHERE'), $field,
																					$this->APP->security->dbescape($value, $this->getSecurityRule($field, 'allow_html')));
	}

	
	/**
	 * @abstract Adds a standard where like %% condition
	 * @param string $field
	 * @param mixed $value
	 * @param string $match
	 * @access public
	 */
	public function whereLike($field, $value, $match = 'AND'){
		$this->sql['WHERE'][] = sprintf('%s %s LIKE "%%%s%%"', (isset($this->sql['WHERE']) ? $match : 'WHERE'), $field,
																					$this->APP->security->dbescape($value, $this->getSecurityRule($field, 'allow_html')));
	}
	
	
	/**
	 * @abstract Searches for values between $start and $end
	 * @param string $field
	 * @param mixed $start
	 * @param string $end
	 * @param string $match
	 * @access public
	 */
	public function whereBetween($field, $start, $end, $match = 'AND'){
		$this->sql['WHERE'][] = sprintf('%s %s BETWEEN "%s" AND "%s"', (isset($this->sql['WHERE']) ? $match : 'WHERE'), $field, $start, $end);
	}

	
	/**
	 * @abstract Adds a standard where greater than condition
	 * @param string $field
	 * @param mixed $value
	 * @param string $match
	 * @access public
	 */
	public function whereGreaterThan($field, $value, $match = 'AND'){
		$this->sql['WHERE'][] = sprintf('%s %s > "%s"', (isset($this->sql['WHERE']) ? $match : 'WHERE'), $field,
																					$this->APP->security->dbescape($value, $this->getSecurityRule($field, 'allow_html')));
	}
	
	
	/**
	 * @abstract Adds a standard where greater than or is equal to condition
	 * @param string $field
	 * @param mixed $value
	 * @param string $match
	 * @access public
	 */
	public function whereGreaterThanEqualTo($field, $value, $match = 'AND'){
		$this->sql['WHERE'][] = sprintf('%s %s >= "%s"', (isset($this->sql['WHERE']) ? $match : 'WHERE'), $field,
																					$this->APP->security->dbescape($value, $this->getSecurityRule($field, 'allow_html')));
	}
	
	
	/**
	 * @abstract Adds a standard where less than condition
	 * @param string $field
	 * @param mixed $value
	 * @param string $match
	 * @access public
	 */
	public function whereLessThan($field, $value, $match = 'AND'){
		$this->sql['WHERE'][] = sprintf('%s %s < "%s"', (isset($this->sql['WHERE']) ? $match : 'WHERE'), $field,
																					$this->APP->security->dbescape($value, $this->getSecurityRule($field, 'allow_html')));
	}
	
	
	/**
	 * @abstract Adds a standard where less than or is equal to condition
	 * @param string $field
	 * @param mixed $value
	 * @param string $match
	 * @access public
	 */
	public function whereLessThanEqualTo($field, $value, $match = 'AND'){
		$this->sql['WHERE'][] = sprintf('%s %s <= "%s"', (isset($this->sql['WHERE']) ? $match : 'WHERE'), $field,
																					$this->APP->security->dbescape($value, $this->getSecurityRule($field, 'allow_html')));
	}
	
	
	/**
	 * @abstract Finds timestamps prior to today
	 * @param string $field
	 * @param boolean $include_today
	 * @param string $match
	 * @access public
	 */
	public function whereBeforeToday($field, $include_today = true, $match = 'AND'){
		$this->sql['WHERE'][] = sprintf('%s TO_DAYS(%s) <%s TO_DAYS(NOW())', (isset($this->sql['WHERE']) ? $match : 'WHERE'), $field, ($include_today ? '=' : ''));
	}
	
	
	/**
	 * @abstract Finds timestamps after today
	 * @param string $field
	 * @param boolean $include_today
	 * @param string $match
	 * @access public
	 */
	public function whereAfterToday($field, $include_today = false, $match = 'AND'){
		$this->sql['WHERE'][] = sprintf('%s TO_DAYS(%s) >%s TO_DAYS(NOW())', (isset($this->sql['WHERE']) ? $match : 'WHERE'), $field, ($include_today ? '=' : ''));
	}
	
	
	/**
	 * @abstract Finds timestamps in the last $day_count days
	 * @param string $field
	 * @param string $day_count
	 * @param string $match
	 * @access public
	 */
	public function inPastXDays($field, $day_count = 7, $match = 'AND'){
		$this->sql['WHERE'][] = sprintf('%s TO_DAYS(NOW()) - TO_DAYS(%s) <= %s', (isset($this->sql['WHERE']) ? $match : 'WHERE'), $field, $day_count);
	}
	
	
//+-----------------------------------------------------------------------+
//| AUTO-FILTER (auto-condition) FUNCTIONS
//+-----------------------------------------------------------------------+


	/**
	 * @abstract Handles incoming filter params in url to add automated conditions to query
	 * @param array $filters
	 * @param array $allowed_filter_keys
	 * @param array $disabled_filters
	 * @return array
	 * @access public
	 */
	public function addFilters($filters = false, $allowed_filter_keys = false, $disabled_filters = false){


		$disabled_filters = $disabled_filters ? $disabled_filters : array();
		$allowed_filter_keys = $allowed_filter_keys ? $allowed_filter_keys : array();

		if($this->APP->params->get->getAlnum('filter')){
			$filters = $this->APP->params->get->getAlnum('filter');
		} else {
			if(isset($_SESSION['filters'][$this->APP->router->getSelectedModule() . ':' . $this->APP->router->getSelectedMethod()])){
				$filters = $_SESSION['filters'][$this->APP->router->getSelectedModule() . ':' . $this->APP->router->getSelectedMethod()];
			}
		}

		if(!$allowed_filter_keys && is_array($filters)){
			$allowed_filter_keys = array_keys($filters);
		}

		if($filters && is_array($filters)){
			foreach($filters as $field => $value){
				if(
					$value != '' &&
					in_array($field, $allowed_filter_keys) &&
					!in_array($field, $disabled_filters) &&
					(array_key_exists(strtoupper($field), $this->getSchema()) || strpos($this->sql['FIELDS'], $field))
					){

					$value_array = false;

					if(strpos($value, ' and ') > 0){
						$value_array = explode(" and ", $value);
					}
					elseif(strpos($value, ' & ') > 0){
						$value_array = explode(" & ", $value);
					}
					elseif(strpos($value, ',') > 0){
						$value_array = explode(",", $value);
					}
					elseif(strpos($value, ' or ') > 0){
						$value_array = explode(" or ", $value);
					}

					if(is_array($value_array)){
						$count = 1;
						foreach($value_array as $match){
							$this->whereLike($field, trim($match), ($count == 1 ? 'AND' : 'OR'));
							$count++;

						}
					} else {

						if(substr($value, 0, 1) == ">"){
							$this->APP->model->whereNot($field, str_replace(">", "", $value));
						} else {
							$this->APP->model->whereLike($field, $value);
						}

					}
				}
				
				if($value === 0){
					$this->APP->model->whereLike($field, 0);
				}
			}
		}

		// save the filters to the session
		$_SESSION['filters'][$this->APP->router->getSelectedModule() . ':' . $this->APP->router->getSelectedMethod()] = $filters;

		return $filters;

	}

	
//+-----------------------------------------------------------------------+
//| SORT AND MATCH GENERATING FUNCTIONS
//+-----------------------------------------------------------------------+

	
	/**
	 * @abstract Adds a sort order, optionally pulls from saved prefs
	 * @param string $field
	 * @param string $dir
	 * @param string $sort_location
	 * @access public
	 */
	public function orderBy($field = false, $dir = false, $sort_location = false){
		
		$field = $field ? $field : $this->table.'.'.$this->getPrimaryKey();
		
		// ensure sort by field has been selected
		if(strpos($this->sql['FIELDS'], '*') === false){
			// explode by fields if any
			$fields = explode(',', $this->sql['FIELDS']);
			if(is_array($fields)){
				// remove any table references
				foreach($fields as $key => $tmp_field){
					$fields[$key] = preg_replace('/(.*)\./', '', $tmp_field);
				}

				// remove any table reference from our field
				$tmp_field = preg_replace('/(.*)\./', '', $field);
				
				// check if our field is in the array of fields
				if(!in_array($tmp_field, $fields)){
					// if not, go with the first item
					$field = $fields[0];
				}
			}
		}
		
		$sort['sort_by'] 		= $field;
		$sort['sort_direction'] = $dir = $dir ? $dir : 'ASC';

		if($sort_location){
			$sort = $this->APP->prefs->getSort($sort_location, false, $field, $dir);
		}
		
		if(empty($sort['sort_by'])){
			$sort['sort_by'] = $field;
		}
			
		if(empty($sort['sort_direction'])){
			$sort['sort_direction'] = $dir;
		}
		
		// verify the field exists, if muliple fields present, skip
		if(strpos($sort['sort_by'], ',') === false && strpos($sort['sort_by'], 'ASC') === false){
			$sort['sort_by'] = array_key_exists(strtoupper($field), $this->getSchema()) || strpos($this->sql['FIELDS'], $field) ? $sort['sort_by'] : $this->table.'.'.$this->getPrimaryKey();
		}
		$this->sql['ORDER'] = sprintf("ORDER BY %s %s", $sort['sort_by'], $sort['sort_direction']);

	}
	
	
	/**
	 * @abstract Limits the results returned
	 * @param integer $start
	 * @param integer $limit
	 * @access public
	 */
	public function limit($start = 0,$limit = 25){
		$start = $start < 0 ? 0 : $start;
		$this->sql['LIMIT'] = sprintf('LIMIT %s,%s', $start, abs($limit));
	}
	
	
	/**
	 * @abstract Adds a fulltext index match function
	 * @param string $search
	 * @param array $fields
	 * @param string $match
	 * @access public
	 */
	public function match($search, $fields = false, $match = 'AND'){
		
		if(!$fields){
			
			$fields = array();

			foreach($this->schema as $field){
				if(in_array($field->type, $this->APP->config('mysql_field_group_text'))){
					$fields[] = $field->name;
				}
			}
		}

		if(is_array($fields) && count($fields)){
			$this->sql['WHERE'][] = sprintf('%s MATCH(%s) AGAINST ("%s" IN BOOLEAN MODE)', (isset($this->sql['WHERE']) ? $match : 'WHERE'), implode(",", $fields), $search);
			$this->addSelectField( sprintf('MATCH(%s) AGAINST ("%s" IN BOOLEAN MODE) as match_relevance', implode(",", $fields), $search) );
		}
	}

	
	/**
	 * @abstract Sets the limit for pagination page numbers
	 * @param integer $current_page
	 * @param integer $per_page
	 * @access public
	 */
	public function paginate($current_page = false,$per_page = 25){
		
		$this->current_page = $current_page ? $current_page : 1;
		$this->per_page = $per_page;
		
		$query_offset = ($current_page - 1) * abs($per_page);
		$this->limit($query_offset,$per_page);
	}

	
	/**
	 * @abstract Sets a group by
	 * @param string $field
	 * @access public
	 */
	public function groupBy($field){
		$this->sql['GROUP'] = sprintf("GROUP BY %s", $field);
	}


//+-----------------------------------------------------------------------+
//| QUERY EXECUTION FUNCTIONS
//+-----------------------------------------------------------------------+
	

	/**
	 * @abstract Builds the query we've designed from the above functions
	 * @return string
	 * @access private
	 */
	private function writeSql(){

		$sql = '';

		// generate the select query
		if(isset($this->sql['SELECT'])){
			
			$this->query_type = 'select';

			$sql .= '' . $this->sql['SELECT'];
			$sql .= ' ' . $this->sql['FIELDS'];
			$sql .= ' ' . $this->sql['FROM'];

			if(isset($this->sql['LEFT_JOIN']) && array($this->sql['LEFT_JOIN'])){
				$sql .= ' ' . implode(" ", $this->sql['LEFT_JOIN']);
			}

			if(isset($this->sql['WHERE']) && array($this->sql['WHERE'])){
				$sql .= ' ' . implode(" ", $this->sql['WHERE']);
			}

			$sql .= ' ' . (isset($this->sql['GROUP']) ? $this->sql['GROUP'] : '');
			
			// if no order set, generate one
			if(!isset($this->sql['ORDER'])){
				$this->orderBy();
			}
			
			$sql .= ' ' . (isset($this->sql['ORDER']) ? $this->sql['ORDER'] : '');
		
			$sql .= ' ' . (isset($this->sql['LIMIT']) ? $this->sql['LIMIT'] : '');

		}
		
		// generate the insert query
		elseif(isset($this->sql['INSERT'])){
			$this->query_type = 'insert';
			$sql = $this->sql['INSERT'];
		}
		
		// generate the update query
		elseif(isset($this->sql['UPDATE'])){
			$this->query_type = 'update';
			$sql = $this->sql['UPDATE'];
		}
		
		else {
			
			$this->select();
			$sql = $this->writeSql();
			
		}

		return $sql;

	}


	/**
	 * @abstract A wrapper for running a query directly to the db, and provided the results directly to the caller
	 * @param string $query
	 * @return object
	 * @access public
	 */
	public function query($query = false){
		
		$results = false;
		
		if($query && !$this->error()){
		
			$this->last_query = $query;
			
			if(!$results = $this->APP->db->Execute($query)){
				// we don't want every query to show as failure here, so we use the true last location
				$back = debug_backtrace();
				$file = strpos($back[0]['file'], 'Model.php') ? $back[1]['file'] : $back[0]['file'];
				$line = strpos($back[0]['file'], 'Model.php') ? $back[1]['line'] : $back[0]['line'];
				
				$this->APP->error->raise(2, $this->APP->db->ErrorMsg() . "\nSQL:\n" . $query, $file, $line);
				
			} else {
				if($this->APP->config('log_verbosity') < 3){
					$this->APP->log->write($query);
				}
			}
		}
		
		$this->clearQuery();
		
		return $results;
		
	}
	
	
	/**
	 * @abstract Runs the generated query and appends any additional info we've selected
	 * @param string $key_field Field value to use for array element key values
	 * @param string $sql Optional sql query replacing any generated
	 * @return array
	 * @access public
	 */
	public function results($key_field = false, $sql = false){
		
		$sql = $sql ? $sql : $this->writeSql();
		
		// if we're doing a select
		if($this->query_type == 'select'){

			$records = array();
			$records['RECORDS'] = array();

			if($results = $this->query($sql)){
	
				if($results->RecordCount()){
					while($result = $results->FetchRow()){
						
						$key = $key_field ? $key_field : $this->getPrimaryKey();
	                   
						if(isset($result[$key]) && !isset($records['RECORDS'][$result[$key]])){
	                    	$records['RECORDS'][$result[$key]] = $result;
	                    } else {
	                    	$records['RECORDS'][] = $result;
	                    }
					}
				} else {
	
					$records['RECORDS'] = false;
	
				}
			} else {
	
				$records['RECORDS'] = false;
	
			}
			
			$this->tmp_records = $records;
	
			// perform any calcs
			if($this->calcs){
				foreach($this->calcs['TOTAL'] as $field){
					$records[strtoupper('TOTAL_' . $field)] = $this->calcTotal($field);
				}
			}
			
			// if any pagination, return found rows
			if($this->paginate){
				$results = $this->query('SELECT FOUND_ROWS()');
				$records['TOTAL_RECORDS_FOUND'] = $results->fields['FOUND_ROWS()'];
				$records['CURRENT_PAGE'] = $this->current_page;
				$records['RESULTS_PER_PAGE'] = $this->per_page;
				$records['TOTAL_PAGE_COUNT'] = ceil($records['TOTAL_RECORDS_FOUND'] / $this->per_page);
			}
			
			$this->tmp_records = false;
			
			return $records;
			
		}
		
		// if we're doing an INSERT
		if($this->query_type == 'insert'){
			if($this->query($sql)){
				return $this->APP->db->Insert_ID();
			}
		}
		
		// if we're doing an UPDATE
		if($this->query_type == 'update'){
			if($this->query($sql)){
				return true;
			} else {
				return false;
			}
		}

		
		$this->clearQuery();
		return false;

	}

	
	/**
	 * @abstract Returns a single field, single-record value from a query
	 *
	 * @param string $sql
	 * @param string $return_field
	 * @return mixed
	 * @access public
	 */
	public function quickValue($sql = false, $return_field = 'id'){
		
		$result = $this->query($sql);
		if($result->RecordCount()){
			while($row = $result->FetchRow()){
				return isset($row[$return_field]) ? $row[$return_field]  : false;
			}
		}
		return false;
	}

	
	/**
	 * @abstract Clears any generated queries
	 * @access public
	 */
	final public function clearQuery(){
		$this->sql = false;
	}
	
	
//+-----------------------------------------------------------------------+
//| AUTO-QUERY-WRITING FUNCTIONS
//+-----------------------------------------------------------------------+


	/**
	 * @abstract Generates a quick select statement for a single record
	 * @param integer $id
	 * @param string $field
	 * @return array
	 * @access public
	 */
	public function quickSelectSingle($id = false, $field = false){

		$field = $field ? $field : $this->getPrimaryKey();
		
		$this->select();
		$this->where($field, $id);
		$record = $this->results($field);
		
		if($record['RECORDS']){
			return $record['RECORDS'][$id];
		}
		return false;
		
	}
	
	
	/**
	 * @abstract Generates a quick select statement for a single record and returns the result as xml
	 * @param integer $id
	 * @return string
	 * @access public
	 */
	public function quickSelectSingleToXml($id = false){
		return $this->APP->xml->arrayToXml( $this->quickSelectSingle($id) );
	}

	
	/**
	 * @abstract Generates and executes a select query
	 * @param integer $id
	 * @param string $field_name
	 * @return boolean
	 * @access public
	 */
	public function delete($id = false, $field_name = false){

		$field_name = $field_name ? $field_name : $this->getPrimaryKey();
		if($this->inSchema($field_name)){
			$this->sql['DELETE'] = sprintf('DELETE FROM %s WHERE %s = "%s"', $this->table, $field_name, $id);
			return $this->query($this->sql['DELETE']);
		}
		return false;
	}
	
	
	/**
	 * @abstract Drops a table completely
	 * @param string $table
	 * @return boolean
	 * @access public
	 */
	public function drop(){
		if($this->table){
			return $this->query(sprintf('DROP TABLE %s', $this->table));
		}
		return false;
	}
	
	
	/**
	 * @abstract Duplicates records using INSERT... SELECT...
	 * @param mixed $id
	 * @param string $field_name
	 * @param string $select_table
	 * @return integer
	 * @access public
	 */
	public function duplicate($id, $field_name = 'id', $replace_field = false){

		$fields = $this->getSchema();

		foreach($fields as $field){
			if(!$field->auto_increment){
				$field_names[] = $field->name;
			}
		}

		$key = $this->getPrimaryKey();

		$sql = sprintf('INSERT INTO %s (' . implode(', ', $field_names) . ') SELECT ' . implode(', ', $field_names) . ' FROM %s WHERE %s = %s ORDER BY %s',
							$table, $table, $field_name, $id, $key);

		if($replace_field){
			$sql = str_replace('SELECT ' . $field_name . ', ', 'SELECT "' . $replace_field . '", ', $sql);
		}

		$this->query($sql);

		return $this->APP->db->Insert_ID();

	}

		
//+-----------------------------------------------------------------------+
//| END-RESULT MANIPULATION FUNCTIONS
//+-----------------------------------------------------------------------+
	
	
	/**
	 * @abstract Adds a field calculation to db results
	 * @param string $field
	 * @param string $type
	 * @access public
	 */
	public function addCalc($field, $type = 'total'){
		$this->calcs[strtoupper($type)][] = $field;
	}
	
	
	/**
	 * @abstract Calculates the total for a field in the resultset
	 * @param array $records
	 * @param string $field
	 * @return float
	 * @access private
	 */
	private function calcTotal($field){

		$total = 0;

		if(is_array($this->tmp_records['RECORDS'])){
			foreach($this->tmp_records['RECORDS'] as $record){
				$total += isset($record[$field]) ? $record[$field] : 0;
			}
		}

		return $total;

	}
	
	
	/**
	 * @abstract Creates a basic table with the results
	 * @param array $row_names
	 * @param array $ignore_fields
	 * @return string
	 * @access public
	 */
	public function createHtmlTable($row_names = false, $ignore_fields = false){
	
		$row_names = is_array($row_names) ? $row_names : array();
	
		$html = '<table>' . "\n";
	
		foreach($this->schema as $field){
			if(!$field->primary_key && !in_array($field->name, $ignore_fields)){
			
				$name = isset($row_names[$field->name]) ? $row_names[$field->name] : $field->name;
				
				// clean name for row title
				$name = ucwords(str_replace("_", " ", $name));
							
				$html .= sprintf('<tr><td><b>%s:</b></td><td>%s</td></tr>' . "\n", $name, $this->APP->form->cv($field->name));
			}
		}
		
		$html .= '</table>' . "\n";
		
		return $html;
	
	}
	
	
//+-----------------------------------------------------------------------+
//| INSERT / UPDATE FUNCTIONS
//+-----------------------------------------------------------------------+
	
	/**
	 * @abstract Generates an INSERT query and auto-executes it
	 * @param array $fields
	 * @return integer
	 * @access private
	 */
	public function insert($fields = false){
		
		if($this->validate($fields)){
	
			if($this->table && is_array($fields)){
			
				$ins_fields = '';
				$ins_values = '';
			
				foreach($fields as $field_name => $field_value){
					if($this->inSchema($field_name)){
				
						$ins_fields .= ($ins_fields == '' ? '' : ', ') . $this->APP->security->dbescape($field_name);
						$ins_values .= ($ins_values == '' ? '' : ', ') . '"' . $this->APP->security->dbescape($field_value, $this->getSecurityRule($field_name, 'allow_html')) . '"';
				
					}
				}
				
				$this->sql['INSERT'] = sprintf('INSERT INTO %s (%s) VALUES (%s)',
									$this->APP->security->dbescape($this->table),
									$ins_fields,
									$ins_values
								);
				
			}
			
			return $this->results();
			
		}
		
		return false;
		
	}
	
	
	/**
	 * @abstract Auto-generates and executes an UPDATE query
	 * @param array $fields
	 * @param mixed $where_value
	 * @param string $where_field
	 * @return boolean
	 * @access private
	 */
	public function update($fields = false, $where_value, $where_field = false ){
		
		if($this->validate($fields)){

			$where_field = $where_field ? $where_field : $this->getPrimaryKey();
			
			if($this->table && is_array($fields)){
			
				$upd_fields = '';
				foreach($fields as $field_name => $field_value){
					if($this->inSchema($field_name)){
						$upd_fields .= ($upd_fields == '' ? '' : ', ') . $this->APP->security->dbescape($field_name) . ' = "' . $this->APP->security->dbescape($field_value, $this->getSecurityRule($field_name, 'allow_html')) . '"';
					}
				}
				
				$this->sql['UPDATE'] = sprintf('UPDATE %s SET %s WHERE %s = "%s"',
													$this->APP->security->dbescape($this->table),
													$upd_fields,
													$this->APP->security->dbescape($where_field),
													$this->APP->security->dbescape($where_value, $this->getSecurityRule($where_field, 'allow_html')));
													
			}
			
			return $this->results();
			
		}
		
		return false;
		
	}
	
	
//+-----------------------------------------------------------------------+
//| FIELD ERROR HANDLING FUNCTIONS
//+-----------------------------------------------------------------------+

	/**
	 * @abstract Adds a new field validation error to the error queue
	 * @param string $field
	 * @param string $message
	 */
	public function addError($field, $message){
		
		$this->error = true;

		if(isset($this->errors[$field]) && is_array($this->errors[$field])){
			array_push($this->errors[$field], $message);
		} else {
			$this->errors[$field] = array($message);
		}
	}
	
	
	/**
	 * @abstract Returns an array of current form errors
	 * @return array
	 * @access public
	 */
	public function getErrors(){
		return $this->errors;
	}
	
	
	/**
	 * @abstract Returns a boolean whether there is a field validation error or not
	 * @return boolean
	 * @access public
	 */
	final public function error(){
		return $this->error;
	}
}
?>