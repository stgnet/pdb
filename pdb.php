<?php

namespace stgnet;

class pdb_field
{
	public $field;	// name
	public $type;	// sql type
	public $null;	// true/false
	public $key;
	public $default;
	public $extra;

	public function __construct($field, $type)
	{
		$this->field = $field;
		$this->type = $type;
		$this->null = True; // default is null field is allowed
		
		return($this);
	}
	public function PrimaryKey()
	{
		$this->key='PRI';
		return($this);
	}
	public function NotNull()
	{
		$this->null = False;
		return($this);
	}
	public function match($other)
	{
		if ($this->field != $other->field) return(False);
		if ($this->type != $other->type) return(False);
		if ($this->null != $other->null) return(False);
		if ($this->key != $other->key) return(False);
		if ($this->extra != $other->extra) return(False);
		return(True);
	}
	public function old_match($describe)
	{
		if ($this->field != $describe['Field']) return(False);
		if ($this->type != $describe['Type']) return(False);
		if ($this->null && $describe['Null']=='NO') return(False);
		if (!$this->null && $describe['Null']=='YES') return(False);
		if ($this->key != $describe['Key']) return(False);
		if ($this->default != $describe['Default']) return(False);
		if ($this->extra != $describe['Extra']) return(False);
		return(True);
	}
	public function definition()
	{
		$definition=$this->field.' '.$this->type;
		if ($this->key == 'PRI') {
			$definition.=' PRIMARY KEY';
		}
		else if ($this->key!='') {
			throw new \Exception('Unimplemented');
		}
		if (!$this->null) {
			$definition.=' NOT NULL';
		}
		if ($this->default || $this->extra) {
			throw new \Exception('Unimplemented');
		}
		return $definition;
	}
}

class pdb
{
	private $table;
	public $pdo;
	private $driver;

	private function pdo_args($params, $omitdbname = False)
	{
		$prefix=$params['pdo'];
		if (!strpos($prefix,':')) {
			$prefix.=':';
		}
		$dsn='';
		foreach ($params as $key => $value)
		{
			if ($key == 'pdo') continue;
			if ($key == 'dbname' && $omitdbname) continue;
			if ($key == 'username') continue;
			if ($key == 'password') continue;
			if ($dsn) $dsn.=';';
			$dsn.=$key.'='.$value;
		}
		$args=array($prefix.$dsn);
		if (!empty($params['username'])) {
			$args[]=$params['username'];
			if (!empty($params['password'])) {
				$args[]=$params['password'];
			}
		}
		return $args;
	}

	private function connect_pdo($pdo_conf, $omitdbname = False)
	{
		$r = new \ReflectionClass('\PDO');
		$pdo = $r->newInstanceArgs(pdb::pdo_args($pdo_conf, $omitdbname));
		$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		if (!$pdo) {
			throw new \Exception('Unable to connect to pdo to create database');
		}
		return $pdo;
	}

	private function connect_pdo_create_database($pdo_conf)
	{
		$pdo = pdb::connect_pdo($pdo_conf, True);
		$result=$pdo->query('CREATE DATABASE '.$pdo_conf['dbname'].';');
	}

	private function connect_pdo_from_config($pdo_conf, $already_tried=False)
	{
		try {
			$pdo = pdb::connect_pdo($pdo_conf);
		} catch (\PDOException $e) {
			if (strpos((string)$e, 'Unknown database') && !$already_tried) {
				pdb::connect_pdo_create_database($pdo_conf);
				return pdb::connect_pdo_from_config($pdo_conf, True);
			}
			throw $e;
		}
		return $pdo;
	}

	public function connect($pdo, $table = NULL, $schema = NULL, $driver = NULL)
	{
		if ($pdo instanceof pdb) {
			return(new self($pdo->driver, $pdo->pdo, $table, $schema));
		}
		if ($pdo instanceof \PDO) {
			return(new self($driver, $pdo, $table, $schema));
		}
		if (gettype($pdo) != 'array') {
			throw new \Exception('Unable to interpret pdo parameter type '.gettype($pdo));
		}
		if (empty($pdo['pdo'])) {
			throw new \Exception('pdo specification not provided');
		}
		// can't do this in php 5.3: $driver = explode(':', $pdo['pdo'])[0];
		$driver = explode(':', $pdo['pdo']);
		$driver = $driver[0];
		$pdo = pdb::connect_pdo_from_config($pdo);
		return(new self($driver, $pdo, $table, $schema));
	}

	private function generic_create_table($table, $schema)
	{
		if (!$schema) {
			throw new \Exception('Cannot create table with empty schema');
		}
		$fields='';
		foreach ($schema as $column)
		{
			if ($fields) $fields.=',';
			$fields.=$column->definition();
		}
		$this->pdo->query('CREATE TABLE '.$table.'('.$fields.');');
	}
	private function sqlite_get_schema($table)
	{
		$schema = array();
		try {
			$result = $this->pdo->query('pragma table_info('.$table.')');
		} catch (\PDOException $e) {
			//echo 'table info failed: '.$e."\n";
			return $schema;
		}
		$existing = $result->fetchAll(\PDO::FETCH_ASSOC);
		/*
		(
		    [cid] => 7
		    [name] => accept
		    [type] => varchar(256)
		    [notnull] => 0
		    [dflt_value] =>
		    [pk] => 0
		)
			public $field;	// name
			public $type;	// sql type
			public $null;	// true/false
			public $key;
			public $default;
			public $extra;
		*/
		foreach ($existing as $have) {
			$field = new pdb_field($have['name'], $have['type']);
			$field->null = ! ($have['notnull']);
			$field->default = $have['dflt_value'];
			if ($have['pk']) $field->PrimaryKey();

			$schema[] = $field;
		}

		return $schema;
	}
	private function mysql_get_schema($table)
	{
		try {
			$describe = $this->pdo->query('describe '.$table);
		} catch (\PDOException $e) {
			if (strpos((string)$e, 'doesn\'t exist')) {
				$describe = NULL;
			} else {
				throw $e;
			}
		}
		if (!$describe) {
			$this->create_table_from_schema($schema);
			$describe = $this->pdo->query('describe '.$table);
		}

		$existing = $describe->fetchAll(\PDO::FETCH_ASSOC);

		$schema = array();
		foreach ($existing as $have) {
			$field = new pdb_field($have['Field'], $have['Type']);
			$field->null = ! ($have['Null']);
			$field->default = $have['Default'];
			if ($have['Key']) $field->PrimaryKey();

			$schema[] = $field;
		}

		return $schema;
	}
	private function generic_add_schema($table, $field)
	{
		$result=$this->pdo->query('ALTER TABLE '.$table.' ADD '.$field->definition().';');
	}
	private function generic_update_schema($table, $field)
	{
		$result=$this->pdo->query('ALTER TABLE '.$table.' MODIFY COLUMN '.$field->definition().';');
	}
	private function load_schema()
	{
		$driver_get_schema = $this->driver.'_get_schema';
		if (!method_exists($this, $driver_get_schema)) {
			$driver_get_schema = 'generic_get_schema';
		}
		if (!method_exists($this, $driver_get_schema)) {
			throw new \Exception('not implemented');
		}
		return $this->$driver_get_schema($this->table);

	}
	private function confirm_schema($schema)
	{
		$driver_create_table = $this->driver.'_create_table';
		$driver_add_schema = $this->driver.'_add_schema';
		$driver_update_schema = $this->driver.'_update_schema';

		if (!method_exists($this, $driver_get_schema)) {
			$driver_get_schema = 'generic_get_schema';
		}
		if (!method_exists($this, $driver_create_table)) {
			$driver_create_table = 'generic_create_table';
		}
		if (!method_exists($this, $driver_add_schema)) {
			$driver_add_schema = 'generic_add_schema';
		}
		if (!method_exists($this, $driver_update_schema)) {
			$driver_update_schema = 'generic_update_schema';
		}

		$existing = $this->$driver_get_schema($this->table);
		if (!$existing) {
			$this->$driver_create_table($this->table, $schema);
			$this->schema = $schema;
		} else {
			foreach ($schema as $required) {
				$found=NULL;
				foreach ($existing as $have) {
					if ($required->field == $have->field) {
						$found = $have;
						break;
					}
				}
				if (!$found) {
					$this->$driver_add_schema($this->table, $required);
				} else {
					if (!$required->match($found)) {
						$this->$driver_update_schema($this->table, $required);
					}
				}
			}
		}
	}

	public function __construct($driver, $pdo, $table, $schema)
	{
		$this->driver = $driver;
		$this->pdo = $pdo;
		$this->table = $table;
		$this->schema = NULL;
		if ($table) {
			if ($schema) {
				$this->confirm_schema($schema);
			} else {
				$schema = $this->load_schema();
			}
			$this->schema = $schema;
		}
	}
	private function prepare_fields($fields, $pattern)
	{
		$values=array();
		foreach ($fields as $name => $value) {
			$values[]=str_replace('%', $name, $pattern);
		}
		return implode(',',$values);
	}
	private function prepare_values($fields,$prefix=':')
	{
		$values=array();
		foreach ($fields as $name => $value) {
			$values[$prefix.$name]=$value;
		}
		return $values;
	}
	public function insert($record)
	{
		
		$stmt=$this->pdo->prepare('INSERT INTO '.$this->table.' ('.implode(',',array_keys($record)).') VALUES('.$this->prepare_fields($record,':%').');');
		$result = $stmt->execute($this->prepare_values($record));
	}
	public function records($where=array(), $like=FALSE)
	{
		if ($like) {
			$pattern='% LIKE :%';
		} else {
			$pattern='% = :%';
		}
		$query='SELECT * FROM '.$this->table;
		if ($where) {
			$query.=' WHERE '.$this->prepare_fields($where,$pattern);
		}
		$stmt=$this->pdo->prepare($query.';');
		$stmt->execute($this->prepare_values($where));
		return($stmt->fetchAll(\PDO::FETCH_ASSOC));
	}
	public function record($where=array())
	{
		$records=$this->records($where);
		if (!$records) return($records);
		if (count($records)==1) return($records[0]);
		throw new \Exception('record() found multiple records'); 
	}
	public function delete($where)
	{
		$stmt=$this->pdo->prepare('DELETE FROM '.$this->table.' WHERE '.$this->prepare_fields($where,'% = :%').';');
		$stmt->execute($this->prepare_values($where));
	}
	public function update($where, $record)
	{
		$stmt=$this->pdo->prepare('UPDATE '.$this->table.
			' SET '.$this->prepare_fields($record,'% = :R%').
			' WHERE '.$this->prepare_fields($where,'% = :W%').';');
		$stmt->execute(array_merge($this->prepare_values($record,':R'),$this->prepare_values($where,':W')));
	}
							   
	public function Field_String($name, $len)
	{
		return new pdb_field($name, "varchar($len)");
	}
	public function Field_Decimal($name, $len, $digits)
	{
		return new pdb_field($name, "decimal($len,$digits)");
	}
	public function Field_DateTime($name)
	{
		return new pdb_field($name, "datetime");
	}
	public function Field_Enum($name, $values)
	{
		return new pdb_field($name, 'enum(\''.implode('\',\'', $values).'\')');
	}
}
