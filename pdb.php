<?php

namespace stgnet;

class pdb_field
{
	public $field;
	public $type;
	public $null; // true/false, output 'YES' or 'NO'
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
	public function match($describe)
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

	private function pdo_dsn($params, $omitdbname = false)
	{
		$prefix=$params['pdo'].':';
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
		return $prefix.$dsn;
	}

	private function connect_pdo_create_database($pdo_conf)
	{
echo "Trying to open pdo\n";
		$pdo = new \PDO(
			pdb::pdo_dsn($pdo_conf, True),
			$pdo_conf['username'],
			$pdo_conf['password']
		);
		$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		if (!$pdo) {
echo "Failed to open pdo\n";
			throw new \Exception('Unable to connect to pdo to create database');
		}
echo "Trying to create database\n".'CREATE DATABASE '.$pdo_conf['dbname'].';'."\n";
		$result=$pdo->query('CREATE DATABASE '.$pdo_conf['dbname'].';');
print_r($result);
echo "After trying to create\n";
		//delete $pdo;
	}

	private function connect_pdo_from_config($pdo_conf, $already_tried=False)
	{
		try {
			$pdo = new \PDO(
				pdb::pdo_dsn($pdo_conf),
				$pdo_conf['username'],
				$pdo_conf['password']
			);
			$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		} catch (\PDOException $e) {
			if (strpos((string)$e, 'Unknown database') && !$already_tried) {
				pdb::connect_pdo_create_database($pdo_conf);
				return pdb::connect_pdo_from_config($pdo_conf, True);
			}
			throw $e;
		}
		return $pdo;
	}

	public function connect($pdo, $table = NULL, $schema = NULL)
	{
		if ($pdo instanceof pdb) {
			return(new self($pdo->pdo, $table, $schema));
		}
		if ($pdo instanceof \PDO) {
			return(new self($pdo, $table, $schema));
		}
		if (gettype($pdo) != 'array') {
			throw new \Exception('Unable to interpret pdo parameter type '.gettype($pdo));
		}
		$pdo = pdb::connect_pdo_from_config($pdo);
		return(new self($pdo, $table, $schema));
	}

	private function create_table_from_schema($schema)
	{
		$fields='';
		foreach ($schema as $column)
		{
			if ($fields) $fields.=',';
			$fields.=$column->definition();
		}
		$this->pdo->query('CREATE TABLE '.$this->table.'('.$fields.');');
	}

	private function confirm_schema($schema)
	{
		if (!$this->table || !$schema) return;
		try {
			$describe = $this->pdo->query('describe '.$this->table);
		} catch (\PDOException $e) {
			if (strpos((string)$e, 'doesn\'t exist')) {
				$describe = NULL;
			} else {
				throw $e;
			}
		}
		if (!$describe) {
			$this->create_table_from_schema($schema);
			$describe = $this->pdo->query('describe '.$this->table);
		}

		$existing = $describe->fetchAll(\PDO::FETCH_ASSOC);
		foreach ($schema as $scol)
		{
			$found=NULL;
			foreach ($existing as $index => $xcol)
			{
				if ($scol->field==$xcol['Field'])
					$found=$index;
			}
			if ($found===NULL)
			{
				$result=$this->pdo->query('ALTER TABLE '.$this->table.' ADD '.$scol->definition().';');
			}
			else
			{
				if (!$scol->match($existing[$found])) {
					echo ('ALTER TABLE '.$this->table.' MODIFY COLUMN '.$scol->definition().';');
					$result=$this->pdo->query('ALTER TABLE '.$this->table.' MODIFY COLUMN '.$scol->definition().';');
				}
			}
		}
	}

	public function __construct($pdo, $table, $schema)
	{
		$this->pdo = $pdo;
		$this->table=$table;
		$this->confirm_schema($schema);
		$this->schema = $schema;

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
		
		$stmt=$this->pdo->prepare('INSERT INTO '.$this->name.' ('.implode(',',array_keys($record)).') VALUES('.$this->prepare_fields($record,':%').');');
		$stmt->execute($this->prepare_values($record));
	}
	public function records($where=array(), $like=FALSE)
	{
		if ($like) {
			$pattern='% LIKE :%';
		} else {
			$pattern='% = :%';
		}
		$query='SELECT * FROM '.$this->name;
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
		$stmt=$this->pdo->prepare('DELETE FROM '.$this->name.' WHERE '.$this->prepare_fields($where,'% = :%').';');
		$stmt->execute($this->prepare_values($where));
	}
	public function update($where, $record)
	{
		$stmt=$this->pdo->prepare('UPDATE '.$this->name.
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

}
