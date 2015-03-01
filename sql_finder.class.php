<?php
/*
 * Dependencies
 */

use \iShare\DB\DB;
use \iShare\DB\DBException;

// base
require_once 'finder.class.php';

class LibIMDB_SQLFinder extends LibIMDB_Finder
{
	/**
	 * Fail - NOP DB
	 *
	 * @param object $_exception {@see DBException}
	 * @return void
	 * @throws object {@see LibIMDB_FinderException}
	 */
	protected function fail_db(&$_exception)
	{
		$_exception	-> nop	();
		$this		-> fail	();
	}

	/*
	 *	FINDING
	 *	===============================================
	 */

	/**
	 * Try a Lookup from DB
	 *
	 * @return bool true => not found; false => found
	 * @throws object {@see LibIMDB_FinderException}
	 */
	protected function try_lookup()
	{
	// SQL
$SQL	=
'SELECT	l.imdb_id
FROM	imdb_lookup AS l
WHERE	l.search = :search AND l.mask = :mask
LIMIT	1;';

		try
		{
			$db			=	DB::getInstance();

			// prepare & execute
			$stmt		=	$db -> prepare($SQL);
			$stmt		->	execute(array
			(
				':search'	=>	$this -> get_title(),
				':mask'		=>	$this -> mask()
			));

			// enough rows?
			if($stmt	->	rowCount()	<	1)
			{
				return true;
			}

			// bind - output / fetch
			$stmt		->	bindColumn('imdb_id', $this -> id, DB :: PARAM_INT);
			$stmt		->	fetch(DB :: FETCH_BOUND);
		}
		catch(DBException $exception)
		{
			$this -> fail_db($exception);
		}

		return $this -> is_valid_id();
	}

	/**
	 * Find IMDB-ID
	 *
	 * @return void
	 * @throws object {@see LibIMDB_FinderException}
	 */
	public function find()
	{
		// found?
		if($this	->	try_lookup())
		{
			// nope; look deeper (search IMDB)
			parent	::	find();
		}
	}

	/*
	 *	SETTING
	 *	===============================================
	 */

	/**
	 * set_components for set()
	 *
	 * @return array sql-components
	 */
	protected function set_components()
	{
		return array
		(
			'REPLACE imdb_lookup(imdb_id, search, mask) VALUES(:id, :title, :mask);',
			array
			(
				':id'		=>	$this -> id,
				':title'	=>	$this -> get_title(),
				':mask'		=>	$this -> mask()
			)
		);
	}

	/**
	 * Set IMDB-ID to DB
	 *
	 * @return void
	 * @throws object {@see LibIMDB_FinderException}
	 */
	public function set()
	{
		list($SQL, $params)	=	$this -> set_components();

		try
		{
			$db			=	DB::getInstance();

			// prepare & execute
			$stmt		=	$db -> prepare($SQL);
			$stmt		->	execute($params);

			if($stmt	->	rowCount() < 1)
			{
				$this	->	fail();
			}
		}
		catch(DBException $exception)
		{
			$this -> fail_db($exception);
		}
	}
}
?>