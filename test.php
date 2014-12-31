<?php

/*
if (file_exists('../../autoload.php'))
	require '../../autoload.php';
else
	require 'vendor/autoload.php';

*/
use stgnet\pdb;

require 'pdb.php';

$mydb=array(
	'pdo'=>'mysql',
	'host'=>'localhost',
	'dbname'=>'pdb_test',
	'charset'=>'utf8',
	'username'=>'root',
	'password'=>''
);

$pdb = pdb::connect($mydb);

$users_schema=array(
	pdb::Field_String('email',128)->PrimaryKey()->NotNull(),
	pdb::Field_String('name',128)->NotNull(),
	pdb::Field_String('pin',16),
	pdb::Field_Decimal('balance',10,2)
);

$db_users=pdb::connect($pdb, 'users', $users_schema);


$lite = pdb::connect(array('pdo' => 'sqlite:test.sqlite'));

$lite_users=pdb::connect($lite, 'users', $users_schema);

