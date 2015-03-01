<?php
/*
 * Dependencies
 */

// common
require_once 'common.php';

// base
require_once 'dom_reader.class.php';

class LibIMDB_ParserException	extends	LibIMDB_Exception {}

class LibIMDB_Parser			extends	LibIMDB_DOMReader
{
	/*
	 *	INTERNAL
	 *	===============================================
	 */

	/**
	 * Keys
	 *
	 * @var array
	 */
	protected $keys				=	array();

	/**
	 * Fetch Soundtrack?
	 *
	 * @var bool
	 */
	protected $get_soundtrack	=	false;

	/*
	 *	INPUT [& OUTPUT]
	 *	===============================================
	 */

	/**
	 * Info - Result Storage
	 *
	 * @var array
	 */
	public $info;

	/**
	 * Construct - Take IMDB-ID
	 *
	 * @param int $_id
	 * @param array|null $_episodes [optional]
	 * @return void
	 */
	public function __construct($_id, $_episodes = null)
	{
		$info					=&	$this -> info;
		$info					=	new \stdClass;
		$info -> id				=&	$_id;

		if(isset($_episodes))
		{
			$info -> contains	=	$_episodes;
		}
	}

	/**
	 * Fetch/Parse Information
	 *
	 * @return void
	 */
	public function fetch()
	{
		$this	->	fetch_main();

		$this	->	fetch_soundtrack();

		$this	->	fetch_seasonal_fullcast();
		$this	->	fetch_episode_list();

		// unset uneccessary variables
		unset($this -> xpath, $this -> dom);
	}

	/**
	 * Issue Failure
	 *
	 * @return void
	 * @throws object {@see LibIMDB_ParserException}
	 */
	protected function fail()
	{
		throw new LibIMDB_ParserException;
	}

	/**
	 * Load XML & Setup xPath
	 *
	 * @param string $_from file-name?
	 * @return void
	 * @throws object {@see LibIMDB_ParserException}
	 */
	protected function load($_from)
	{
		parent::load("http://imdb.com/title/tt{$this -> info -> id}/{$_from}");
	}

	/*
	 *	GENERIC :: DOM, STRING
	 *	===============================================
	 */

	/**
	 * Reduce white-space
	 *
	 * @param string $_text text to transform
	 * @return void
	 */
	protected function reduce_ws($_text)
	{
		return preg_replace('/\s+/S', ' ', $_text);
	}

	/**
	 * Remove the paranthesis around text
	 *
	 * @param string $_text
	 * @return string
	 */
	protected function remove_parenthesises($_text)
	{
		return str_replace(array('(', ')'), '', $_text);
	}

	/**
	 * Get the text
	 *
	 * @param object $_in {@see DOMElement}
	 * @return object {@see DOMNodeList}
	 */
	protected function query_text($_in)
	{
		return $this -> xpath -> evaluate('./text()', $_in);
	}

	/**
	 * Get the text, directly
	 *
	 * @param object $_in {@see DOMElement}
	 * @param $_xpath string-query
	 * @return string text
	 */
	protected function parse_simple($_in, $_xpath = null)
	{
		$node	=	is_null($_xpath) ? $this -> query_text($_in) : $this -> xpath -> evaluate($_xpath, $_in);

		return	trim($node -> item(0) -> nodeValue);
	}

	/**
	 * Parse a very simple block
	 *
	 * @param string $_from name of block
	 * @return string
	 */
	protected function parse_quick(&$_info, $_xpath = null)
	{
		return $this -> reduce_ws($this -> parse_simple($_info), $_xpath);
	}

	/**
	 * Get a array of text-pairs (with anchors)
	 *
	 * @param string $_from
	 * @param array $_to where to?
	 * @return void
	 */
	protected function parse_text_pair(&$_from, &$_to)
	{
		foreach($this -> query_anchor($_from) as $from)
		{
			$pair			=	new \stdClass;
			$pair -> info	=	trim($from -> nodeValue);

			$next			=&	$from -> nextSibling -> nextSibling;

            $pair -> extra	=	isset($next	->	nodeName) && $next -> nodeName != 'a'
							?	trim($this -> remove_parenthesises($next -> nodeValue))
							:	''

			$_to[]	=	$pair;
		}
	}

	/**
	 * Parse a Key
	 *
	 * @param object $_link {@see DOMElement}
	 * @param int $_what type of key?
	 * @param bool $_first is-first?
	 * @return string key-id-hash
	 */
	protected function parse_key(&$_key, $_what, $_first = false)
	{
		// get key
		$key	=	$this -> parse_link($_key, $_first);

		// composite-hash
		$hash	=	$_what . '#'. $key -> id;

		// hash is new? => set
		if(empty($this -> keys[$hash]))
		{
			$key	->	what		=	$_what;

			$this	->	keys[$hash]	=	$key;
		}

		return $_what	==	LibIMDB_ENUM::KEY_CHARACTER	? $key : $hash;
	}

	/*
	 *	FETCH, PARSE
	 *	===============================================
	 */

	/**
	 * Fetch (Parse) Main info
	 *
	 * @return void
	 * @throws object {@see LibIMDB_ParserException}
	 */
	protected function fetch_main()
	{
		$this -> load('combined');

		/*
		 * Parse
		 */
		$run_list	=	array
		(
			'release date'		=>	1,
			'genre'				=>	1,
			'tagline'			=>	1,
			'plot'				=>	1,
			'awards'			=>	1,
			'runtime'			=>	1,
			'country'			=>	1,
			'color'				=>	1,
			'aspect ratio'		=>	1,
			'sound mix'			=>	1,
			'certification'		=>	1,
			'soundtrack'		=>	1,
			'seasons'			=>	1,
		);

		/*
		 * Search For Blocks
		 */
		foreach
		(
			$this -> xpath -> evaluate
			(
				'//div[@class="info"]',
				$this -> dom -> getElementById('tn15content')
			)
			as $block
		)
		{
			$info	=	$this -> xpath -> evaluate('./div[@class="info-content"]', $block) -> item(0);

			if(is_null($info))
			{
				continue;
			}

			// get header
			$what	=	$this -> xpath -> evaluate('./h5', $block) -> item(0) -> nodeValue;

			// find first parenthesis
			$paranthesis_pos	=	strpos($what, ' (');

			$what	=	strtolower
			(
				// get important part
				substr
				(
					$what,
					0,
					// parenthesis found? => there, else => first colon
					$paranthesis_pos === false ? strpos($what, ':') : $paranthesis_pos
				)
			);

			// run
			if(isset($run_list[$what]))
			{
				$this -> {'parse_' . str_replace(' ', '_', $what)}($info);
			}
		}

		$this -> parse_title();
		$this -> parse_rating();
		$this -> parse_plot_keywords();

		$this -> parse_cast();
		$this -> parse_company();
	}

	/**
	 * Parse Title
	 *
	 * @return void
	 */
	protected function parse_title()
	{
		$this -> info -> title	=	$this -> parse_simple($this -> dom -> getElementById('tn15title'), './h1/text()');
	}

	/**
	 * Count N Seasons
	 *
	 * @param object $_info {@see DOMElement}
	 * @return void
	 */
	protected function parse_seasons(&$_info)
	{
		$count	=	count(explode('|', $_info->nodeValue));

		if($count == 0)
		{
			$count++;
		}

		$this -> info -> seasons	=	$count;
	}

	/**
	 * Parse the Rating
	 *
	 * @return void
	 */
	protected function parse_rating()
	{
		$rating	=	$this -> dom -> getElementById('tn15rating');

		if(empty($rating))
		{
			return;
		}

		// get elems
		$rating		=	$this -> xpath -> evaluate('.//b | .//a', $rating);

		$info	=&	$this -> info -> rating;
		$info	=	new \stdClass;

		// get rate
		list($info -> value)	=	explode('/', $rating -> item(0) -> nodeValue, 2);
		$info -> value			=	(double) trim($info -> value);

		// get voters
		$info -> voters			=	(int) preg_replace('/\D/', '', trim($rating -> item(1) -> nodeValue));
	}

	/*	CAST LIST
	 *	-----------------------------------------------
	 */

	/**
	 * Parse a "List of Cast-members"
	 *
	 * @param object $_list {@see DOMNodeList}
	 * @param string $_where where-to?
	 * @return void
	 */
	protected function parse_cast_list(&$_list, $_where)
	{
		foreach($_list as $cast)
		{
			$cast	=&	$cast -> childNodes;

			if($cast -> length < 2)
			{
				// empty...
				continue;
			}

			$key		=	$this -> parse_key($cast -> item(0), LibIMDB_ENUM::KEY_PERSON, true);

			$relation	=	preg_replace
			(
				'/ \...:?/',
				'',
				preg_replace
				(
					'/ \(/',
					': ',
					trim
					(
						$this -> reduce_ws($cast -> item(2) -> nodeValue),
						"\n\t )("
					)
				)
			);

			if(empty($relation))
			{
				$info				=	$key;
			}
			else
			{
				$info				=	new \stdClass;
				$info -> person		=	$key;
				$info -> relation	=	$relation;
			}

			$this -> info -> cast -> {$_where}[]	=	$info;
		}
	}

	/**
	 * Parse Director (Series)
	 *
	 * @param object $_directors {@see DOMNodeList}
	 * @return void
	 */
	protected function parse_cast_series_directed($_directors)
	{
		$this -> parse_cast_list($_directors, 'directors');
	}

	/**
	 * Parse Director
	 *
	 * @param object $_directors {@see DOMNodeList}
	 * @return void
	 */
	protected function parse_cast_directed(&$_directors)
	{
		$this -> parse_cast_list($_directors, 'directors');
	}

	/**
	 * Parse Writers (Series)
	 *
	 * @param object $_writers {@see DOMNodeList}
	 * @return void
	 */
	protected function parse_cast_series_writing_credits(&$_writers)
	{
		$this -> parse_cast_list($_writers, 'writers');
	}

	/**
	 * Parse Writers
	 *
	 * @param object $_writers {@see DOMNodeList}
	 * @return void
	 */
	protected function parse_cast_writing_credits(&$_writers)
	{
		$this -> parse_cast_list($_writers, 'writers');
	}

	/**
	 * Parse Producers (Series)
	 *
	 * @param object $_producers {@see DOMNodeList}
	 * @return void
	 */
	protected function parse_cast_series_produced(&$_producers)
	{
		$this -> parse_cast_list($_producers, 'producers');
	}

	/**
	 * Parse Producers
	 *
	 * @param object $_producers {@see DOMNodeList}
	 * @return void
	 */
	protected function parse_cast_produced(&$_producers)
	{
		$this -> parse_cast_list($_producers, 'producers');
	}

	/**
	 * Parse Original-Music (Series)
	 *
	 * @param object $_musicians {@see DOMNodeList}
	 * @return void
	 */
	protected function parse_cast_series_original_music(&$_musicians)
	{
		$this -> parse_cast_list($_musicians, 'org_music');
	}

	/**
	 * Parse Original-Music
	 *
	 * @param object $_musicians {@see DOMNodeList}
	 * @return void
	 */
	protected function parse_cast_original_music(&$_musicians)
	{
		$this -> parse_cast_list($_musicians, 'org_music');
	}

	/**
	 * Parse Players
	 *
	 * @param object $_players {@see DOMNodeList}
	 * @return void
	 */
	protected function parse_cast_players(&$_players)
	{
		foreach($_players as $player)
		{
			$player	=&	$player -> childNodes;

			$i		=	0;
			$chars	=	array();

			foreach($player -> item(3) -> childNodes as $node)
			{
				if($node instanceof \DOMElement)
				{
					$char			=	new \stdClass;
					$char -> info	=&	$this -> parse_key($node, LibIMDB_ENUM::KEY_CHARACTER);
					$char -> stable	=	true;
					$char -> extra	=	'';

					$chars[]	=	$char;
					$i++;
				}
				elseif($node instanceof \DOMText)
				{
					$value	=	trim($this -> reduce_ws($node -> nodeValue));

					if($value == '/')
					{
					}
					else if($value[0] == '(')
					{
						if($i === 0)
						{
							$where	=	0;

							$char				=	new \stdClass;
							$char -> info		=	'';
							$char -> stable		=	false;

							$chars[]	=	$char;
							$i++;
						}
						else
						{
							$where	=	$i - 1;
						}

						$chars[$where] -> extra	=	substr($value, 1, -1);
					}
					else
					{
						foreach(explode('/', $value) as $complex)
						{
							$complex	=	trim($complex);

							if(mb_strlen($complex) < 2)
							{
								break;
							}

							$complex	=	explode(' (', trim($complex));

							if($complex[0]	==	'...')
							{
								$chars[$i - 1] -> extra	=	trim(str_replace(')', '', $complex[1]));
								break;
							}

							$char			=	new \stdClass;
							$char->info		=	trim($complex[0]);
							$char->stable	=	false;
							$char->extra	=	count($complex) < 2 ? '' : trim(str_replace(')', '', $complex[1]));

							$chars[]	=	$char;
							$i++;
						}
					}
				}
			}

			$_player			=	new \stdClass;
			$_player -> actor	=	$this -> parse_key($player -> item(1) -> firstChild, LibIMDB_ENUM::KEY_PERSON);
			$_player -> chars	=	$chars;

			$this -> info -> cast -> players[]	=	$_player;
		}
	}

	/**
	 * Parse the casting
	 *
	 * @return void
	 */
	protected function parse_cast()
	{
		$run_list	=	array
		(
			'players'					=>	1,
			'directed'					=>	1,
			'writing credits'			=>	1,
			'produced'					=>	1,
			'original music'			=>	1,
			'series directed'			=>	1,
			'series writing credits'	=>	1,
			'series produced'			=>	1,
			'series original music'		=>	1,

			'recommended'				=>	1,
		);

		$cast	=&	$this -> info -> cast;

		if(is_null($cast))
		{
			$cast	=	new \stdClass;
		}

		// major cast-block
		$blocks_xml	=	$this -> xpath -> evaluate('//table', $this -> dom -> getElementById('tn15content'));

		foreach($blocks_xml as $block)
		{
			// get header
			$what_pre	=	$this -> xpath -> evaluate('.//h5', $block);

			if($what_pre -> length)
			{
				$predicate	=	'[position() > 1]';

				$what	=	strtolower($what_pre -> item(0) -> nodeValue);

				// remove "by" if found
				$where_by	=	strpos($what, 'by');
				if($where_by !== false)
				{
					$what	=	substr($what, 0, $where_by - 1);
				}
			}
			else
			{
				$class	=	$block -> getAttribute('class');

				if($class == 'cast')
				{
					$predicate	=	'[@class]';
					$what		=	'players';
				}
				else if($class == 'recs')
				{
					$predicate	=	'[2]/td/a';
					$what		=	'recommended';
				}
				else
				{
					continue;
				}
			}

			// run it
			if(isset($run_list[$what]))
			{
				$a	=	'./tbody/tr' . $predicate;

				$nodes	=	$this -> xpath -> evaluate('./tr' . $predicate, $block);

				$this -> {'parse_cast_' . str_replace(' ', '_', $what)}($nodes);
			}
		}
	}

	/**
	 * Parse Recommended Titles
	 *
	 * @param object $_info {@see DOMNodeList}
	 * @return unknown_type
	 */
	protected function parse_cast_recommended(&$_info)
	{
		foreach($_info as $title)
		{
			$this -> info -> recommended[]	=	$$this -> parse_link($title);
		}
	}

	/**
	 * Parse Released Time
	 *
	 * @param object $_info {@see DOMElement}
	 * @return void
	 */
	protected function parse_release_date(&$_info)
	{
		$released	=&	$this -> info -> released;
		$released	=	$this -> parse_quick($_info);

		// to unix-time
		$released	=	strtotime(substr($released, 0, strpos($released, ' (')));
	}

	/**
	 * Parse Genre(s)
	 *
	 * @param object $_info {@see DOMElement}
	 * @return void
	 */
	protected function parse_genre(&$_info)
	{
		foreach($this -> query_anchor($_info) as $genre)
		{
			if($genre -> nodeValue == 'more')
			{
				continue;
			}
	
			$this -> info -> genres[]	=	trim($genre -> nodeValue);
		}
	}

	/**
	 * Parse Tag-line
	 *
	 * @param object $_info {@see DOMElement}
	 * @return void
	 */
	protected function parse_tagline(&$_info)
	{
		$this -> info -> tagline	=	$this -> parse_quick($_info);
	}

	/**
	 * Parse Plot
	 *
	 * @param object $_info {@see DOMElement}
	 * @return void
	 */
	protected function parse_plot(&$_info)
	{
		$this -> info -> plot	=	$this -> parse_quick($_info);
	}

	/**
	 * Parse Plot-Keywords
	 *
	 * @return void
	 */
	protected function parse_plot_keywords()
	{
		$keywords	=	$this -> dom -> getElementById('tn15plotkeywords');

		if(!$keywords)
		{
			return;
		}

		foreach($this -> query_anchor($keywords) as $keyword)
		{
			$this -> info -> plot_keywords[]	=	strtolower(trim($keyword -> nodeValue));
		}
	}

	/**
	 * Parse Awards
	 *
	 * @param object $_info {@see DOMElement}
	 * @return void
	 */
	protected function parse_awards(&$_info)
	{
		$this -> info -> awards	=	$this -> parse_quick($_info);
	}

	/**
	 * Parse Runtime
	 *
	 * @param object $_info {@see DOMElement}
	 * @return void
	 */
	protected function parse_runtime(&$_info)
	{
		$runtime	=&	$this -> info -> runtime;

		$info	=	preg_replace
		(
			'/\D/',
			'',
			explode('|', $this -> parse_simple($_info), 2)
		);

		$runtime -> normal		=	(int) $info[0];
		$runtime -> extended	=	isset($info[1]) ? (int) $info[1] : 0;
	}

	/**
	 * Parse Countries
	 *
	 * @param object $_info {@see DOMElement}
	 * @return void
	 */
	protected function parse_country(&$_info)
	{
		foreach($this -> query_anchor($_info) as $country)
		{
			$this -> info -> countries[]	=	trim($country -> nodeValue);
		}
	}

	/**
	 * Parse Is-Color
	 *
	 * @param object $_info {@see DOMElement}
	 * @return void
	 */
	protected function parse_color(&$_info)
	{
		$this -> info -> is_colored	=	'color' == strtolower
		(
			$this -> parse_simple($_info, './a/text()')
		);
	}

	/**
	 * Parse Aspect-Ratio
	 *
	 * @param object $_info {@see DOMElement}
	 * @return void
	 */
	protected function parse_aspect_ratio(&$_info)
	{
		$this -> info -> aspect_ratio	=	$this -> parse_quick($_info);
	}

	/**
	 * Parse Sound-Mix
	 *
	 * @param object $_info {@see DOMElement}
	 * @return void
	 */
	protected function parse_sound_mix(&$_info)
	{
		$this -> parse_text_pair($_info, $this -> info -> sound_mix);
	}

	/**
	 * Parse Certifications
	 *
	 * @param object $_info {@see DOMElement}
	 * @return void
	 */
	protected function parse_certification(&$_info)
	{
		$this -> parse_text_pair($_info, $this -> info -> certifications);
	}

	/**
	 * Parse Company
	 *
	 * @return void
	 */
	protected function parse_company()
	{
		foreach($this -> xpath -> evaluate('./ul[1]/li/a', $this -> dom -> getElementById('tn15content')) as $company)
		{
			$this -> info -> companies[]	=	$this -> parse_key($company, LibIMDB_ENUM::KEY_COMPANY);
		}
	}

	/*	SOUNDTRACK
	 *	-----------------------------------------------
	 */

	/**
	 * Parse Soundtrack ( check if has, interpret later )
	 *
	 * @param object $_info {@see DOMElement}
	 * @return void
	 */
	protected function parse_soundtrack(&$_info)
	{
		$this -> get_soundtrack	=	true;
	}

	/**
	 * Fetch Soundtrack
	 *
	 * @return void
	 */
	protected function fetch_soundtrack()
	{
		if($this -> get_soundtrack)
		{
			$this -> load('soundtrack');

			foreach
			(
				$this -> xpath -> evaluate
				(
					'./ul[@class="trivia"]/li',
					$this -> dom -> getElementById('tn15content')
				) as $soundtrack
			)
			{
				$info			=	new \stdClass;
				$info -> song	=	trim($soundtrack -> firstChild -> nodeValue, "\"\n ");

				foreach($this -> xpath -> evaluate('.//a', $soundtrack) as $person)
				{
					$info -> persons[]	=	$this -> parse_key($person, LibIMDB_ENUM::KEY_PERSON);
				}

				$this -> info -> soundtrack[]	=	$info;
			}
		}

		unset($this -> get_soundtrack);
	}

	/*	TVShow
	 *	-----------------------------------------------
	 */

	/**
	 * Parse full Cast if Seasonal (TV-Show)
	 *
	 * @return void
	 */
	protected function fetch_seasonal_fullcast()
	{
		if(empty($this -> info -> seasons))
		{
			return;
		}

		// clear
		$this -> info -> cast -> players	=	array();

		$this -> load('fullcredits');

		// parse
		$this -> parse_cast_players
		(
			$this -> xpath -> evaluate
			(
				'./table[@class="cast"]/tr[@class]',
				$this -> dom -> getElementById('tn15content')
			)
		);
	}

	/**
	 * Parse Episode-List if Seasonal (TV-Show)
	 *
	 * @return void
	 */
	protected function fetch_episode_list()
	{
		$info	=&	$this -> info;

		if(empty($info -> seasons))
		{
			return;
		}

		$this -> load('epdate');

		/*
		 * Count of __used__ Seasons
		 */
		$has_contains	=	isset($info -> contains);

		if($has_contains)
		{
			$contains			=	$used_seasons		=	array();
			$contains_seasons	=	$contains_episodes	=	0;
		}

		/*
		 * Parse Episodes
		 */
		foreach
		(
			$this -> xpath -> evaluate
			(
				'./table/tr[position() > 1]',
				$this -> dom -> getElementById('tn15content')
			)
			as $title
		)
		{
			$columns	=	$this -> xpath -> evaluate('./td[position() < 5]', $title);

			$episode	=	$this -> parse_link($columns -> item(1), true);

			if($columns -> length > 2)
			{
				$episode -> rating			=	(double)	$columns -> item(2) -> nodeValue;
				$episode -> rating_voters	=	(int)		$columns -> item(3) -> nodeValue;
			}

			// parse: season/episode - index
			$ordinals		=			explode('.', $columns -> item(0) -> nodeValue);
			$season_index	=	(int)	$ordinals[0];
			$episode_index	=	(int)	$ordinals[1];
			unset($ordinals);

			$info -> seasonal_info[$season_index][$episode_index]	=	$episode;

			/*
			 * Active Episodes:
			 * (If available)
			 */
			if($has_contains && isset($info -> contains[$season_index]))
			{
				$a_season	=&	$info -> contains[$season_index];

				if($a_season === LibIMDB_ENUM::EPISODE_FULL || isset($a_season[$episode_index]))
				{
					// increase contains-seasons if first time for this season
					if(!array_key_exists($season_index, $used_seasons))
					{
						$used_seasons[$season_index]	=	null;
						$contains_seasons++;
					}

					// increase contains-episodes if first time for this episode
					if(!array_key_exists($episode -> id, $contains))
					{
						$contains[$episode -> id]	=	null;
						$contains_episodes++;
					}
				}
			}
		}

		/*
		 * Finish Active Episodes
		 */
		// contains any specific episode?
		if(!empty($contains))
		{
			// yes;	transfer temporary contains
			$info -> contains	=	$contains;

			if($contains_episodes === 1)
			{
				// single
				reset($info -> contains);
				$info -> contains_short	=	key($info -> contains);
			}
			else
			{
				// multi
				$info -> contains_short	=	(double) "{$contains_seasons}.{$contains_episodes}";
			}
		}
		else
		{
			// no;	make sure no contains are there
			unset($info -> contains);
		}
	}
}
?>