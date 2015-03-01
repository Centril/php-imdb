<?php
/*
 * Dependencies
 */

use \iShare\DB\DB;
use \iShare\DB\DBException;

// base
require_once 'parser.class.php';

class LibIMDB_SQLParser extends LibIMDB_Parser
{
	/*
	 *	INTERNAL
	 *	===============================================
	 */

	/**
	 * Cache time
	 * - minimum time (seconds) before next refresh
	 * - 86400 = 1 d (3600 [1 h] * 24)
	 *
	 * @var int
	 */
	const CACHE_TIME			=	86400;

	/**
	 * SQL-string
	 *
	 * @var array|string
	 */
	protected $sql				=	array
	(
		'main'					=>	'',
		'keys'					=>	'',
		'keys_bind'				=>	'',
		'episodes'				=>	'',
		'rec'					=>	'',
		'ACT'					=>	'',
	);

	/*
	 *	FETCHING
	 *	===============================================
	 */

	/**
	 * Fetch/Parse Information
	 *
	 * @return bool need-update?
	 */
	public function fetch()
	{
		if(!self::DEBUG && $this -> is_cached())
		{
			return false;
		}

		parent::fetch();

		return true;
	}

	/**
	 * Is-Cached?
	 *
	 * @return bool
	 */
	protected function is_cached()
	{
$SQL	=
'SELECT	UNIX_TIMESTAMP() - UNIX_TIMESTAMP(i.last_updated) <= :cache AS is_cached
FROM	imdb AS i
WHERE	i.imdb_id = :id
LIMIT	1;';

		try
		{
			$db		=	DB::getInstance();

			// prepare
			$stmt	=	$db -> prepare($SQL);
			$stmt	->	execute(array
			(
				'id'	=>	$this -> info -> id,
				'cache'	=>	self::CACHE_TIME
			));

			// enough rows?
			if($stmt	->	rowCount()	<	1)
			{
				// nope;
				return false;
			}

			// bind & fetch
			$stmt	->	bindColumn('is_cached', $is_cached, DB::PARAM_BOOL);
			$stmt	->	fetch(DB::FETCH_BOUND);
		}
		catch(\DBException $exception)
		{
			// failure, assume not cached
			$exception -> nop();
			return false;
		}

		return $is_cached;
	}

	/*
	 *	SQL
	 *	===============================================
	 */

	/**
	 * Append SQL-Trait
	 *
	 * @param string $_what what type of trait?
	 * @param array $_info information to parse
	 * @param string $_sql sql-string to parse to
	 * @return void
	 */
	protected function SQLTrait($_what, $_info)
	{
		static $first	=	true;

		if(empty($_info))
		{
			// trait is empty, return
			return;
		}

		$db	=	DB::getInstance();

		foreach($_info as $trait)
		{
			$this -> test_first($first, $this -> sql['ACT']);

			$traitSQL	=	is_object($trait)
						?	"{$db -> quote($trait -> info)}, {$db -> quote($trait -> extra)}"
						:	"{$db -> quote($trait)}, ''";

			$this -> sql['ACT']	.=	"\n({$this -> info -> id}, 0, {$_what}, {$traitSQL})";
		}
	}

	/**
	 * Append SQL-Key
	 *
	 * @param string $_relation
	 * @param string $_key_id hash {what, key_id}
	 * @param string $_extra extra-text
	 * @param bool $_comma has comma?
	 * @return int
	 */
	protected function SQLKey($_relation, $_key_id, $_extra = '', $_index = null)
	{
		static $first	=	true;

		$db		=	DB::getInstance();

		$this	->	test_first($first, $this -> sql['keys_bind']);

		list($what, $key_id)	=	explode('#', $_key_id);

		if(is_null($_index))
		{
			$_index	=	'NULL';
		}

		$this -> sql['keys_bind']	.=	"\n({$this -> info -> id}, {$what}, {$key_id}, {$_relation}, {$db -> quote($_extra)}, {$_index})";

		return $key_id;
	}

	/**
	 * Append SQL-Complex (Vector of Keys)
	 *
	 * @param $_relation
	 * @param object|string $_info contains info
	 * @return unknown_type
	 */
	protected function SQLComplex($_relation, &$_info)
	{
		if(empty($_info))
		{
			// empty complex, return
			return;
		}

		foreach($_info as $info)
		{
			if(is_object($info))
			{
				$this	->	SQLKey	($_relation, $info -> person, $info -> relation);
			}
			else
			{
				$this	->	SQLKey	($_relation, $info);
			}
		}
	}

	/**
	 * If not first time, add comma
	 *
	 * @param bool $_first
	 * @param string $_to
	 * @return void
	 */
	protected function test_first(&$_first, &$_to)
	{
		if($_first)
		{
			$_first		=	false;
		}
		else
		{
			$_to		.=	',';
		}
	}

	/**
	 * Prepare values
	 *
	 * @return void
	 */
	protected function SQLPrepare()
	{
		$info	=&	$this -> info;

		if(empty($info -> rating -> value))
		{
			$info -> rating -> value		=
			$info -> rating -> voters		=	'NULL';
		}

		$info -> released	=	empty($info -> released)
							?	'NULL'
							:	"FROM_UNIXTIME({$info -> released})";
	
		if(empty($info -> seasons))
		{
			$info -> seasons				=	'NULL';
		}

		if(empty($info -> runtime -> normal))
		{
			$info -> runtime -> normal		=
			$info -> runtime -> extended	=	'NULL';
		}
		else if(empty($info -> runtime -> extended))
		{
			$info -> runtime -> extended	=	'NULL';
		}

		if(is_null($info -> is_colored))
		{
			$info -> is_colored				=	'NULL';
		}

		if(empty($info -> aspect_ratio))
		{
			$info -> aspect_ratio			=	'NULL';
		}

		if(empty($info -> tagline))
		{
			$info -> tagline				=	'NULL';
		}
	
		if(empty($info -> plot))
		{
			$info -> plot					=	'NULL';
		}
	
		if(empty($info -> awards))
		{
			$info -> awards					=	'NULL';
		}
	}

	/**
	 * Form/Execute SQL
	 *
	 * @return void
	 * @throws object {@see LibIMDB_ParserException}
	 */
	public function SQL()
	{
		$info	=&	$this -> info;
		$this	->	SQLPrepare();

		try
		{
			$db	=&	DB::getInstance();

			/*
			 * Keys
			 */
			if(!empty($this -> keys))
			{
				$this	->	sql['keys'] = "INSERT IGNORE imdb_key(`what`, `key_id`, `name`)\nVALUES";

				$first	=	true;

				foreach($this -> keys as $key)
				{
					$this	->	test_first($first, $this -> sql['keys']);

					$this	->	sql['keys'] .= "\n({$key -> what}, {$key -> id}, {$db -> quote($key -> text)})";
				}
			}

			/*
			 * Traits
			 */

			// genres
			$this	->	SQLTrait(	LibIMDB_ENUM::TRAIT_GENRE,			$info	->	genres			);

			// plot-keywords
			$this	->	SQLTrait(	LibIMDB_ENUM::TRAIT_PLOT_KEYWORD,	$info	->	plot_keywords	);

			// countries
			$this	->	SQLTrait(	LibIMDB_ENUM::TRAIT_COUNTRY,		$info	->	countries		);

			// sound-mixes
			$this	->	SQLTrait(	LibIMDB_ENUM::TRAIT_SOUND_MIX,		$info	->	sound_mix		);

			// certifications
			$this	->	SQLTrait(	LibIMDB_ENUM::TRAIT_CERTIFICATION,	$info	->	certifications	);

			/*
			 * Keys
			 */
			$cast	=&	$info -> cast;

			// directors
			$this	->	SQLComplex(	LibIMDB_ENUM::PERSON_DIRECTOR,	$cast	->	directors	);

			// writers
			$this	->	SQLComplex(	LibIMDB_ENUM::PERSON_WRITER,	$cast	->	writers		);

			// producers
			$this	->	SQLComplex(	LibIMDB_ENUM::PERSON_PRODUCER,	$cast	->	producers	);

			// original music
			$this	->	SQLComplex(	LibIMDB_ENUM::PERSON_ORG_MUSIC,	$cast	->	org_music	);

			// companies
			$this	->	SQLComplex(	1,								$info	->	companies	);

			// "players"
			if(isset($cast		->	players))
			{
				foreach($cast	->	players		as	$player)
				{
					$actor_id	=	$this -> SQLKey(LibIMDB_ENUM::PERSON_PLAYER, $player -> actor);

					foreach($player -> chars	as	$char)
					{
						if(isset($char	->	stable))
						{
							if($char	->	stable)
							{
								$this -> sql['ACT']	.=	",\n({$info -> id}, {$actor_id}, {$char -> info -> id}, {$db -> quote($char -> info -> text)}, {$db -> quote($char -> extra)})";
							}
							else
							{
								$this -> sql['ACT']	.=	",\n({$info -> id}, {$actor_id}, 0, {$db -> quote($char -> info)}, {$db -> quote($char -> extra)})";
							}
						}
					}
				}
			}

			/*
			 * Soundtrack
			 */
			if(isset($info		->	soundtrack))
			{
				$i	=	0;

				foreach($info	->	soundtrack	as	$soundtrack)
				{
					$this -> sql['ACT']	.=	", \n({$this -> info -> id}, 0, " .
											LibIMDB_ENUM::TRAIT_SOUNDTRACK.", {$db -> quote($soundtrack -> song)}, {$i})";

					if(isset($soundtrack	->	persons))
					{
						foreach($soundtrack	->	persons	as	$person)
						{
							$this -> SQLKey(LibIMDB_ENUM::PERSON_SOUNDTRACK, $person, '', $i);
						}
					}

					$i++;
				}
			}

$this -> sql['main']	=	<<<END
REPLACE imdb
(
	`imdb_id`, `rating`, `rating_voters`, `released`, `is_colored`,
	`seasons`, `runtime`, `runtime_extended`, `aspect_ratio`,
	`tagline`, `plot`, `awards`, `title`,
	`last_updated`
)
VALUES
(
	{$info	->	id}, {$info -> rating -> value}, {$info -> rating -> voters}, {$info -> released}, {$info -> is_colored},
	{$info	->	seasons}, {$info -> runtime -> normal}, {$info -> runtime -> extended}, {$db -> quote($info -> aspect_ratio)},
	{$db	->	quote($info -> tagline)},
	{$db	->	quote($info -> plot)},
	{$db	->	quote($info -> awards)},
	{$db	->	quote($info -> title)},
	NOW()
)
END;
		
			if(!empty($this -> sql['keys_bind']))
			{
				$this -> sql['keys_bind'] =
					"INSERT imdb_key_imdb(`imdb_id`, `what`, `key_id`, `relation`, `extra`, `index`)\nVALUES".
					$this -> sql['keys_bind'];
			}

			if(!empty($this -> sql['ACT']))
			{
				$this -> sql['ACT'] =
					"INSERT imdb_actor_char(`imdb_id`, `actor_id`, `char_id`, `name`, `extra`)\nVALUES".
					$this -> sql['ACT'].
					';';
			}

			/*
			 * Seasonal-Info (Eplist)
			 */
			if(isset($info		->	seasonal_info))
			{
				$this	->	sql['episodes']	=	"INSERT imdb_episode(`imdb_id`, `season`, `episode`, `episode_id`, `title`, `rating`, `rating_voters`)\nVALUES";

				$first	=	true;

				foreach($info	->	seasonal_info	as	$season_index	=>	$season)
				{
					foreach($season					as	$episode_index	=>	$episode)
					{
						$this	->	test_first($first, $this -> sql['episodes']);

						if(empty($episode	->	rating))
						{
							$episode		->	rating		=	'NULL';
							$episode		->	rating_voters	=&	$episode->rating;
						}

						$this -> sql['episodes'] .=
							"\n(".
								$info -> id						. ', ' .
								$season_index					. ', ' .
								$episode_index					. ', ' .
								$episode -> id					. ', ' .
								$db -> quote($episode -> text)	. ', ' .
								$episode -> rating				. ', ' .
								$episode -> rating_voters		.
							')';
					}
				}
			}

			if(isset($info		->	recommended))
			{
				$this	->	sql['rec'] = "INSERT imdb_recommended(`imdb_id`, `recommended`, `title`)\nVALUES";

				$first	=	true;

				foreach($info	->	recommended as $rec)
				{
					$this -> test_first($first, $this -> sql['rec']);

					$this -> sql['rec']	.=	"\n({$info -> id}, {$rec -> id}, {$db -> quote($rec -> text)})";
				}
			}

			// unset used info
			unset($this -> keys, $this -> info);	

			foreach($this -> sql as $key => $sql)
			{
				if(empty($sql))
				{
					unset($this -> sql[$key]);
				}
			}

			// Join & Run: SQL
			if($db -> exec(join(";\n", $this -> sql)) < 1)
			{
				$this -> fail();
			}

			unset($this);
		}
		catch(DBException $exception)
		{
			$exception	->	nop();
			$this		->	fail();
		}
	}
}
?>