<?php
/*
 * Dependencies
 */

// common
require_once 'common.php';

class LibIMDB_TVShows
{
	/**
	 * Holds name
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * Init
	 *
	 * @param string $_name name of torrent
	 * @return void
	 */
	public function __construct(&$_name)
	{
		$this -> name	=&	$_name;
	}

	/**
	 * Walk a range of something
	 *
	 * @param int $_lower lower bound
	 * @param int $_upper upper bound
	 * @param callback $_func function to apply
	 * @return void
	 */
	protected function walk_range(&$_lower, &$_upper, $_func)
	{
		if($_lower	>	$_upper)
		{
			$temp	=	$_lower;
			$_lower	=	$_upper;
			$_upper	=	$temp;
		}

		for(; $_lower < ($_upper + 1); $_lower++)
		{
			$_func($_lower);
		}
	}

	/**
	 * Try a match
	 *
	 * @param string $_regex
	 * @param array $_matches
	 * @return bool matched?
	 */
	protected function match(&$_regex, &$_matches)
	{
		if(preg_match($_regex ,$this -> name, $_matches, PREG_OFFSET_CAPTURE))
		{
			// remove match
			$start		=	$_matches[0][1];

			$this -> name	=	($start === 0 ? null : mb_substr($this -> name, 0, $start)) .
								' ' .
								mb_substr($this -> name, $start + mb_strlen($_matches[0][0]));

			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Find out if there are any full seasons
	 *
	 * @param array $_seasons [out] list of full seasons
	 * @return bool complete? [yes] => were done
	 */
	protected function full_seasons(&$_seasons)
	{
		$complete_regex	=
		'[^a-z0-9\s]?
		\s?
		(?:
			complete	|	#	english
			hela			#	swedish
		)
		\s?
		[^a-z0-9\s]?';

		$regex =
'{
	(?P<complete_1>											#	complete?
		' . $complete_regex . '
		\s?
	)?
	(?:
		(?:												#	starts with "season"
			\b
			(?:
				seasons?				|	#	english
				säsong(?:er(?:na)?)?	|	#	swedish
				saison(?:s)?			|	#	french
				seizo(en){1,2}			|	#	dutch
				staffeln?				|	#	german
				temporadas?					#	spanish
			)
			\s?
			0?(?P<lower_1>							#	lower number
				\d\d?
			)
			(?P<rest_1>							#	possibility of more numbers
				(?:							#	range block
					\s?
					(?:					#	range delimiter
						-	|
						to
					)
					\s?
					0?(?P<upper_1>		#	upper bound
						\d\d?
					)
				)				|
				(?:							#	explicit block		
					\s?
					(?:and|,|;|&)		#	"and" delimiter
					\s?
					0?(?:				#	explicit number
						\d\d?
					)
				)+
			)?
		)							|
		(?:												#	starts with "s"
			\b
			s
			0?(?P<lower_2>							#	lower number
				\d\d?
			)
			(?P<rest_2>								#	possibility of more numbers
				(?:								#	possiblity of range
					-							#	range delimiter
					s?
					0?(?P<upper_2>				#	upper bound
						\d\d?
					)
				)				|
				(?:								#	explicit block
					[,;&\s]					#	and delimiter
					s
					0?(?:					#	explicit number
						\d\d?
					)
				)+
			)?
			\b
		)
	)
	(?P<complete_2>											#	complete?
		\s
		' . $complete_regex . '
	)?
}ix';

		unset($complete_regex);

		// any matches?
		if($this -> match($regex, $matches))
		{
			// remove all unneccesary elements
			foreach($matches as $key => &$value)
			{
				if(is_int($key) || $value[0] === '')
				{
					unset($matches[$key]);
				}
			}

			$_seasons	=	array();

			/*
			 *	functor:
			 *		set a season
			 *
			 *	@param int $num number to set
			 *	@return void
			 */
			$set		=	function($num) use(&$_seasons)
			{
				$_seasons[(int)$num]	=	LibIMDB_ENUM::EPISODE_FULL;
			};

			/*
			 *	functor:
			 *		test if - has any of possible indexes ($keys) - &
			 *		get name of first possible index
			 *
			 *	@param array $keys list of possible indexes
			 *	@param bool $_key_name get the key-name that matched?
			 *	@return bool|string if isset:{$key or true} else false
			 */
			$isset	=	function($keys, $_key_name = false) use(&$matches)
			{
				foreach($keys as $key)
				{
					if(isset($matches[$key]))
					{
						return $_key_name ? $key : true;
					}
				}

				return false;
			};

			/*
			 *	functor:
			 *		test if - has any of possible indexes ($keys) - &
			 *		get value from exact index {first possible index} into $result
			 *
			 *	@param array $keys list of possible indexes
			 *	@param string|int $result result value goes here
			 *	@param bool $make_int typecast $result to int?
			 *	@return bool is-set?
			 */
			$get	=	function($keys, &$result, $make_int = false) use(&$matches, &$isset)
			{
				$key	=	$isset($keys, true);

				if($key)
				{
					$result	=	$make_int
							?	(int) $matches[$key][0]
							:	$matches[$key][0];

					return true;
				}

				return false;
			};

			// lower number
			if($get(array('lower_1', 'lower_2'), $lower, true))
			{
				// range mode
				if($get(array('upper_1', 'upper_2'), $upper, true))
				{
					// no range (singular) => set
					if($upper	==	$lower)
					{
						$set($lower);
					}
					// range (multi) => loop the range & set
					else
					{
						// try a swap (if in wrong order)
						$this -> walk_range($lower, $upper, $set);
					}
				}
				else
				{
					// multi
					if($get(array('rest_1', 'rest_2'), $rest))
					{
						$rest		=	preg_split('/\D+/', $rest);
						$rest[0]	=	$lower;

						array_walk($rest, $set);
					}
					// singular
					else
					{
						$set($lower);
					}
				}
			}

			// complete? => we're done (totally)
			if($isset(array('complete_1', 'complete_2')))
			{
				return true;
			}
		}
	}

	/**
	 * Find out episodes
	 *
	 * @return array list of episodes/seasons
	 */
	public function tell_episodes()
	{
		if(!$this -> full_seasons($ep_list))
		{
			$regex	=
'{
	\b
	(?:
		s0?(\d\d?)e		|	#	s4e	/	s04e	/	s044e
		0?(\d\d?)x			#	4x	/	04x		/	044x
	)
	0?(\d\d?)					#	lower bound
	(?:							#	possiblity of range
		-					#	range delimiter
		0?(\d\d?)			#	upper bound
	)?
	\b
}Six';

			// loop over matches
			while($this -> match($regex, $matches))
			{
				$episode	=	array();

				// list actual values
				for($i = 1; $i < count($matches); $i++)
				{
					$val	=&	$matches[$i][0];

					if($val	!==	'')
					{
						$episode[]	=	(int) $val;
					}
				}

				$season		=&	$episode[0];

				// make sure we don't override a full season
				if(!isset($ep_list[$season]) || $ep_list[$season] !== ENUM::EPISODE_FULL)
				{
					/*
					 *	functor:
					 *		set an episode
					 *
					 *	@param int $num number(episode) to set
					 *	@return void
					 */
					$set	=	function($num) use(&$ep_list, &$season)
					{
						$ep_list[$season][$num]	=	LibIMDB_ENUM::EPISODE_FULL;
					};

					// range (multi) => loop the range & set
					if(isset($episode[2]))
					{
						$this -> walk_range($episode[1], $episode[2], $set);
					}
					// singular
					else
					{
						$set($episode[1]);
					}
				}
			}

			unset($matches);
		}

		// done
		return $ep_list;
	}
}
?>