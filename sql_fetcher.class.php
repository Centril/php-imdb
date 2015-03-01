<?php
/*
 * Dependencies
 */

use \iShare\DB\DB;
use \iShare\DB\DBException;

// common
require_once 'common.php';

class LibIMDB_SQLFetcher
{
	/*
	 *	INTERNALS
	 *	===============================================
	 */

	/**
	 * Table [Fetch => ACT/Keys]: Associative => Int
	 *
	 * @var array
	 */
	public $assoc_table;

	/*
	 *	INPUT & OUTPUT
	 *	===============================================
	 */

	/**
	 * Holds Info
	 *
	 * @var object
	 */
	public $info;

	/**
	 * Init Fetcher
	 *
	 * @param int $_id
	 * @param float $_episode [optional]
	 * @return void
	 */
	public function __construct($_id, $_episode = null)
	{
		// init Info with ID
		$info			=&			$this -> info;
		$info			=			new \stdClass;
		$info -> id		=	(int)	$_id;

		// episode info if available
		$this -> episode_proto($_episode);
	}

	/**
	 * Handle episode prototype
	 *
	 * @param float $_episode
	 * @return void
	 */
	protected function episode_proto($_episode)
	{
		// has info?
		if(isset($_episode) && $_episode != 0)
		{
			$contains	=&	$this -> info -> contains;
			$contains	=	new \stdClass;

			// "float-part"
			$dot_pos	=	strpos($_episode, '.');

			if($dot_pos	===	false)
			{
				// ID
				$contains -> id		=	(int)	$_episode;
			}
			else
			{
				// Multi
				$contains -> n_s	=	(int)	$_episode;
				$contains -> n_e	=	(int)	substr($_episode, $dot_pos + 1);
			}
		}
	}

	/*
	 *	INFO FETCHER (and Helpers)
	 *	===============================================
	 */

	/**
	 * SQL: for Fetching
	 *
	 * @return string SQL
	 */
	protected function fetch_sql()
	{
		$options	=&	$this -> options;

		// ensure we have options
		if(empty($options))
		{
			$options					=	array
			(
				'extra'					=>	true,
				'sound_mixes'			=>	true,
				'certifications'		=>	true,
				'soundtracks'			=>	true,
				'companies'				=>	true,
				'cast'					=>	true,
				'cast_players'			=>	true,
				'cast_directors'		=>	true,
				'cast_writers'			=>	true,
				'cast_producers'		=>	true,
				'cast_original_music'	=>	true,
			);
		}

		// Adjust Assoc Table
		{
			$assoc_table			=&	$this -> assoc_table;
			$assoc_table			=	array
			(
				'name'				=>	0,
				'key_id'			=>	1,
				'relation'			=>	2,
			);

			$assoc_table_count		=	count($this -> assoc_table);

			$assoc_table['extra']	=	$options['extra']		?	$assoc_table_count ++	:	-1;
			$assoc_table['index']	=	$options['soundtracks']	?	$assoc_table_count ++	:	-1;
			$assoc_table['what']	=	$assoc_table_count;
		}

		// Sound-Mixes?
		if(!$options['sound_mixes'])
		{
			$act_noinc[]	=	LibIMDB_ENUM :: TRAIT_SOUND_MIX;
		}

		// Certifications?
		if(!$options['certifications'])
		{
			$act_noinc[]	=	LibIMDB_ENUM :: TRAIT_CERTIFICATION;
		}

		// Soundtracks?
		if(!$options['soundtracks'])
		{
			$act_noinc[]	=	LibIMDB_ENUM :: TRAIT_SOUNDTRACK;
			$key_noinc[]	=	LibIMDB_ENUM :: PERSON_SOUNDTRACK;
		}

		// Companies?
		if(!$options['companies'])
		{
			$key_what_noinc[]	=	LibIMDB_ENUM :: KEY_COMPANY;
		}

		// Cast?
		if(!$options['cast'])
		{
			foreach($options as $key => &$option)
			{
				if(substr($key, 0, 5) == 'cast_')
				{
					$option = false;
				}
			}
		}

		// Cast => Players?
		if(!$options['cast_players'])
		{
			$key_noinc[]		=	LibIMDB_ENUM :: PERSON_PLAYER;
			$key_what_noinc[]	=	LibIMDB_ENUM :: KEY_CHARACTER;
		}

		// Cast => Directors?
		if(!$options['cast_directors'])
		{
			$key_noinc[]		=	LibIMDB_ENUM :: PERSON_DIRECTOR;
		}

		// Cast => Writers?
		if(!$options['cast_writers'])
		{
			$key_noinc[]		=	LibIMDB_ENUM :: PERSON_WRITER;
		}

		// Cast => Producers?
		if(!$options['cast_producers'])
		{
			$key_noinc[]		=	LibIMDB_ENUM :: PERSON_PRODUCER;
		}

		// Cast => Original Music?
		if(!$options['cast_original_music'])
		{
			$key_noinc[]		=	LibIMDB_ENUM :: PERSON_ORG_MUSIC;
		}

		// No Keys?
		{
			// no companies, no soundtracks => go on
			if(!$options['companies'] && !$options['soundtracks'])
			{
				$keys_none	=	true;

				/*
				 * cast is enabled;
				 * check if has true child => getting keys
				 */
				if($options['cast'])
				{
					foreach($options as $key => $option)
					{
						if(substr($key, 0, 5) == 'cast_')
						{
							if($option)
							{
								$keys_none	=	false;
								break;
							}
						}
					}
				}
			}
			else
			{
				// getting keys
				$keys_none	=	false;
			}
		}

		/*
		 * ACT ( Actor - Character / Table)
		 */
$actSQL	=
	'(
		SELECT' .
			$this -> inline_resultset(array
			(
				'ac.name',
				'ac.char_id',
				'ac.actor_id',
				$this -> if_extra('ac.extra'),
			)) . '
		FROM	imdb_actor_char AS ac
		WHERE
			ac.imdb_id = i.imdb_id' .
			$this -> if_false($options['cast_players'], $this -> and_sql() . 'ac.actor_id = 0') .
			$this -> not_in('ac.char_id',	$act_noinc) . '
		GROUP BY
			ac.imdb_id
	) AS _act';

		/*
		 * Keys
		 */
$keySQL  =	$this -> if_false
(
	$keys_none,
	'(
		SELECT' .
			$this -> inline_resultset(array
			(
				'k.name',
				'ki.key_id',
				'ki.relation',
				$this -> if_extra('ki.extra'),
				$this -> if_false(!$options['soundtracks'], 'ki.index'),
				'ki.what',
			)) . '
		FROM	imdb_key_imdb AS ki
		INNER JOIN
			imdb_key AS k
			ON
				k.what	 = ki.what	AND
				k.key_id = ki.key_id
		WHERE
			ki.imdb_id = i.imdb_id' .
			$this -> not_in('ki.what',		$key_what_noinc) .
			$this -> not_in('ki.relation',	$key_noinc) . '
	) AS _keys'
);

		/*
		 * Episode
		 */
$epSQL	=	$this -> if_false
(
	empty($this -> info -> contains -> id),
	'(
		SELECT' .
			$this -> inline_resultset(array
			(
				'e.season',
				'e.episode',
				'e.title',
				'e.rating',
				'e.rating_voters'
			), false) . '
		FROM	imdb_episode AS e
		WHERE	e.episode_id = :episode_id
		LIMIT	1
	) AS _episode'
);

		/*
		 * Assemble All
		 */
$SQL	=
'SELECT' .
	$this -> columns(array
	(
		$actSQL, $keySQL, $epSQL,
		'i.rating',		$this -> if_extra('i.rating_voters'),
		'i.runtime',	$this -> if_extra('i.runtime_extended'),
		'i.is_colored',	'i.aspect_ratio',
		'i.seasons',	'UNIX_TIMESTAMP(i.released) AS released',
		'i.tagline',	'i.plot',
		'i.awards',		'i.title'
	)) . '
FROM	imdb AS i
WHERE	i.imdb_id = :imdb_id
LIMIT	1;';

		return $SQL;
	}

	/**
	 * Fetch info from DB for parsing
	 *
	 * @param object $_info location-to
	 * @return bool success or failure
	 */
	protected function fetch()
	{
		try
		{
			$db			=	DB :: getInstance();

			/*
			 * Make [SQL]: GROUP_CONCAT() failsafe
			 */
			$concatSQL	=	'SET SESSION group_concat_max_len = 1048576;';
			$db			->	exec($concatSQL);

			/*
			 * Fetch Info
			 */
			$info		=&	$this -> info;

			// setup params
			$params		=	array(':imdb_id' => $info -> id);

			// only add episode_id if not = 0
			if(isset($info -> contains -> id))
			{
				$params[':episode_id']	=	$info -> contains -> id;
			}

			// prepare SQL
			$stmt		=	$db -> prepare($this -> fetch_sql());

			// execute
			$stmt		->	execute($params);

			// has result?
			if($stmt	->	rowCount() < 1)
			{
				// no; fail
				return false;
			}

			// fetch
			$this -> raw	=	$stmt -> fetch();
		}
		catch(DBException $exception){}

		// success?
		return empty($this	->	raw);
	}

	/**
	 * Fetcher
	 *
	 * @return bool success?
	 */
	public function info()
	{
		/*
		 * Fetch
		 */
		if($this -> fetch())
		{
			return false;
		}

		/*
		 * Process Main Info
		 *
		 * Workaround ( ugly hack ) for:
		 * "Fatal error: Cannot use $this as lexical variable"
		 */
		$self	=&	$this;

		$instructions	=	array
		(
			'title',
			'tagline',
			'plot',
			'awards',
			'aspect_ratio',
			'released'		=>	'int',
			'is_colored'	=>	'bool',
			'seasons'		=>	'int',
			'rating'		=>	array('value'	=> 'float',	'voters'	=> 'int'),
			'runtime'		=>	array('normal'	=> 'int',	'extended'	=> 'int'),
			'episode'		=>	function($raw)	use($self)
			{
				/*
				 * Episode
				 * TABLE:
				 *		season			=>	0
				 * 		episode			=>	1
				 * 		name/title		=>	2
				 * 		rating			=>	3
				 * 		rating_voters	=>	4
				 */
				$episode								=&				$self -> info -> contains;
				$episode		-> season				=	(int)		$raw[0];
				$episode		-> episode				=	(int)		$raw[1];
				$episode		-> title				=				$raw[2];

				if(isset($raw[3]))
				{
					$episode	-> rating				=				new	\stdClass;
					$episode	-> rating -> value		=	(double)	$raw[3];
					$episode	-> rating -> voters		=	(int)		$raw[4];
				}
			},
			'act'			=>	function($act)	use(&$self, &$ac_lookup)
			{
				$info	=&	$self -> info;

				if($act[ $self	->	assoc_table[ 'relation' ] ]	==	0)		//	Trait
				{
					switch($act[ $self	->	assoc_table[ 'key_id' ] ])
					{
						case LibIMDB_ENUM :: TRAIT_GENRE:					//	Genre

							$self		->	get_trait_plain	($info -> genres,			$act);

							break;

						case LibIMDB_ENUM :: TRAIT_PLOT_KEYWORD:			//	Plot - Keyword

							$self		->	get_trait_plain	($info -> plot_keywords,	$act);

							break;

						case LibIMDB_ENUM :: TRAIT_COUNTRY:					//	Country

							$self		->	get_trait_plain	($info -> countries,		$act);

							break;

						case LibIMDB_ENUM :: TRAIT_SOUND_MIX:				//	Sound - Mix

							$self		->	get_trait_pair	($info -> sound_mix,		$act);

							break;

						case LibIMDB_ENUM :: TRAIT_CERTIFICATION:			//	Certification

							$self		->	get_trait_pair	($info -> certifications,	$act);

							break;

						case LibIMDB_ENUM :: TRAIT_SOUNDTRACK:				//	Soundtrack - (Song Part)

							if($self	->	options['extra'])
							{
								$info	->	soundtrack[ (int) $act[ $self -> assoc_table[ 'extra' ] ] ]
										->	song
										=	$act[ $self -> assoc_table[ 'name' ] ];
							}
							else
							{
								$info	->	soundtrack[]
										=	$act[ $self -> assoc_table[ 'name' ] ];
							}

							break;
					}
				}
				else														//	Character
				{
					// make actor -> characters lookup for players
					$self	->	get_info
					(
						$ac_lookup[ $act[ $self -> assoc_table[ 'relation' ] ] ][],
						'ch',
						$act,
						true
					);
				}
			},
			'keys'			=>	function($key)	use(&$self, &$ac_lookup)
			{
				$info	=&	$self	->	info;

				switch($key[ $self	->	assoc_table[ 'what' ] ])
				{
					case LibIMDB_ENUM :: KEY_COMPANY:						//	Company

						$self		->	get_info($info -> companies[], 'co', $key);

						break;

					case LibIMDB_ENUM :: KEY_PERSON:						//	Person

						$cast		=&	$info -> cast;

						switch($key[ $self -> assoc_table[ 'relation' ] ])
						{
							case LibIMDB_ENUM :: PERSON_DIRECTOR:			//	Director

								$self	->	get_person_info($cast -> directors[],	$key);

								break;

							case LibIMDB_ENUM :: PERSON_WRITER:				//	Writer

								$self	->	get_person_info($cast -> writers[],		$key,	true);

								break;

							case LibIMDB_ENUM :: PERSON_PRODUCER:			//	Producer

								$self	->	get_person_info($cast -> producers[],	$key,	true);

								break;

							case LibIMDB_ENUM :: PERSON_ORG_MUSIC:			//	Original - Music

								$self	->	get_person_info($cast -> org_music[],	$key);

								break;

							case LibIMDB_ENUM :: PERSON_PLAYER:				//	Players

								// we have ac-lookup?
								if(empty($ac_lookup))
								{
									// nope; skip
									continue;
								}

								// parse actor
								$self	->	get_person_info($person,				$key);

								// add characters
								foreach($ac_lookup[ $key[ $self -> assoc_table[ 'key_id' ] ] ] as $ac)
								{
									$person -> chars[]	=	$ac;
								}

								// add to list of players
								$cast	->	players[]	=	$person;

								break;

							case LibIMDB_ENUM :: PERSON_SOUNDTRACK:			//	Soundtrack - persons part

								if($self	->	options['extra'])
								{
									$self	->	get_person_info
									(
										$info	->	soundtrack[ (int) $key[ $self -> assoc_table[ 'index' ] ] ]
												->	persons[],
										$key
									);
								}

								break;
						}
						break;
				}
			}
		);

		// parse
		array_walk($instructions, array(&$this, 'parse_instruction'));

		// unset junk
		unset($instructions, $this -> raw, $this -> assoc_table);

		// success ?
		return !empty($this -> info);
	}

	/*
	 *	INFO PARSER HELPERS
	 *	===============================================
	 */

	/**
	 * Route a parsing instruction
	 *
	 * @param string|callback|array $_value
	 * @param int|string $_key
	 * @return void
	 */
	public function parse_instruction($_value, $_key)
	{
		is_int($_key) ? $this -> parse_value($_value)
					  : $this -> {'parse_'.
						 		 (
										is_string($_value)
									?	'value'
						 			:	(
												is_callable($_value)
											?	'table'
						 					:	'duplex'
										)
						 		 )}
								 ($_key, $_value);
	}

	/**
	 * Get & Unset a variable from raw
	 *
	 * @param string $_name name of property
	 * @return value of variable that was unset
	 */
	protected function & get_unset($_name)
	{
		// store temporarily
		$temp	=	$this -> raw -> {$_name};

		// unset
		unset($this -> raw -> {$_name});

		// return
		return $temp;
	}

	/**
	 * Move variable from raw to $_to
	 *
	 * @param object $_to will recieve a member
	 * @param array|string $_names name(s) of properties
	 * @return mixed [ref]
	 */
	public function & move_variable(&$_to, $_names)
	{
		if(is_string($_names))
		{
			$_names	=	array($_names, $_names);
		}

		// get & unset
		$_to		-> {$_names[1]}	=	$this -> get_unset($_names[0]);

		// return
		return $_to -> {$_names[1]};
	}

	/**
	 * Parse a value
	 *
	 * @param string $_name property-name
	 * @param string $_type [optional] type-to-cast-to; default is string
	 * @return void
	 */
	public function parse_value($_name, $_type = 'string')
	{
		// exists in {@raw} ?
		if(isset($this -> raw -> {$_name}))
		{
			// move from {@raw} to {@info}
			$ref	=&	$this -> move_variable($this -> info, $_name);

			// set type
			if($_type !== 'string')
			{
				settype($ref, $_type);
			}
		}
	}

	/**
	 * Parse a duplex (pair)
	 *
	 * @param string $_base_name base-name to look for
	 * @param array $_duplex names of parts
	 * @return void
	 */
	public function parse_duplex($_base_name, array $_duplex)
	{
		if(isset($this -> raw -> {$_base_name}))
		{
			$duplex		=	new \stdClass;
			$self		=&	$this;

			$part		=	function(&$_from_name) use(&$_duplex, &$duplex, &$self)
			{
				// get actions
				list($to_name, $type)	=	each($_duplex);

				// move & set-type
				settype($self -> move_variable($duplex, array($_from_name, $to_name)), $type);
			};

			$part($name	= $_base_name);

			if(isset($this -> raw -> {$_base_name = $_base_name . '_' . key($_duplex)}))
			{
				$part($_base_name);
			}

			// done; set
			$this -> info -> {$name}	=	$duplex;
		}
	}

	/**
	 * Parse a table
	 *
	 * @param string $_name table name (where to find)
	 * @param object $_from where to find table
	 * @param callback $_func column/row parser
	 * @return void
	 */
	protected function parse_table($_name, $_func)
	{
		// table exists?
		if(isset($this -> raw -> {($_name = '_' . $_name)}))
		{
			// break up in rows
			foreach(explode("\n", $this -> get_unset($_name)) as $row)
			{
				// break up in columns
				$_func(explode("\t", $row));
			}
		}
	}

	/**
	 * Gets info {name, id [,extra]} from a db-result
	 *
	 * @param mixed $_to info goes here
	 * @param string $_what prepended to id
	 * @param object $_key source (db-result)
	 * @param bool $_extra get extra? [optional]
	 * @return void
	 */
	public function get_info(&$_to, $_what, &$_key, $_extra = false)
	{
		$_to			=	new \stdClass;
		$_to -> name	=&	$_key[$this -> assoc_table['name']];

		$_to -> id		=
		(
				$_key[ $this -> assoc_table['key_id'] ]	==	0
			?	''
			:	$_what . $_key[ $this -> assoc_table['key_id'] ]
		);

		if
		(
			$_extra											&&
			isset($_key[ $this -> assoc_table['extra'] ])	&&
			$_key[ $this -> assoc_table['extra'] ]	!=	''
		)
		{
			$_to -> position	=	$_key[ $this -> assoc_table['extra'] ];
		}
	}

	/**
	 * Gets person info {name, id [,extra]} from a db-result
	 *
	 * @param mixed $_to info goes here
	 * @param object $_key source (db-result)
	 * @param bool $_extra get extra? [optional]
	 * @return void
	 */
	public function get_person_info(&$_to, &$_key, $_extra = false)
	{
		$this -> get_info($_to, 'nm', $_key, $_extra);
	}

	/**
	 * Gets a trait pair {type, extra} from a db-result
	 *
	 * @param mixed $_to trait-pair goes here
	 * @param object $_key source (db-result)
	 * @return void
	 */
	public function get_trait_pair(&$_to, &$_trait)
	{
		$_temp				=	new \stdClass;
		$_temp -> type		=&	$_trait[ $this -> assoc_table['name']	];
		$_temp -> extra		=&	$_trait[ $this -> assoc_table['extra']	];

		$_to[]	=	$_temp;
	}

	/**
	 * Gets a trait-plain-value from a db-result
	 *
	 * @param string $_to trait-value
	 * @param object $_key source (db-result)
	 * @return void
	 */
	public function get_trait_plain(&$_to, &$_trait)
	{
		$_to[]	=	$_trait[ $this -> assoc_table['name'] ];
	}

	/*
	 *	SQL HELPERS
	 *	===============================================
	 */

	/**
	 * If $_predicate is false => return $_success
	 *
	 * @param bool $_predicate
	 * @param string $_success
	 * @return string
	 */
	protected function if_false($_predicate, $_success)
	{
		return $_predicate ? null : $_success;
	}

	/**
	 * SQL: AND clause
	 *
	 * @return string SQL
	 */
	protected function and_sql()
	{
		return "\tAND\n" . str_repeat("\t", 3);
	}

	/**
	 * SQL: "NOT IN($)" clause
	 *
	 * @param string $_column sql-column name
	 * @param array $_not_in array of int(s)
	 * @return string|null
	 */
	protected function not_in($_column, &$_not_in)
	{
		if(!empty($_not_in))
		{
			return $this -> and_sql() . $_column . " NOT IN(" . join(',', $_not_in) . ')';
		}
	}

	/**
	 * SQL: if-extra-enabled column(s) clause
	 *
	 * @param $_columns
	 * @return string
	 */
	protected function if_extra($_columns)
	{
		return ($this -> options['extra'] ? $_columns : null);
	}

	/**
	 * SQL: Columns
	 *
	 * @param array $_columns
	 * @param int $_indent
	 * @return string
	 */
	protected function columns($_columns, $_indent = 1)
	{
		foreach($_columns as $key => $column)
		{
			if(is_null($column))
			{
				unset($_columns[$key]);
			}
		}

		$repeat	=	"\n" . str_repeat("\t", $_indent);

		return $repeat . join(','.$repeat, $_columns);
	}

	/**
	 * SQL: Inline Resultset
	 *
	 * @param array $_rows
	 * @param bool $_multi many or one row
	 * @return string SQL
	 */
	protected function inline_resultset($_rows, $_multi = true)
	{
		return '
			' . ($_multi ? 'GROUP_CONCAT(' : null) .
				'CONCAT_WS
			' . ($_multi ? "\t" : null) .
				'(' .
					$this -> columns(array_merge(array('"\t"'), $_rows), ($_multi ? 5 : 4)) . '
			' . ($_multi ? "\t" : null) .
				')' .
			($_multi ? '
				SEPARATOR "\n"
			)' : null);
	}
}
?>