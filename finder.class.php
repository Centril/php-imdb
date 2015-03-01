<?php
/*
 * Dependencies
 */

// common
require_once 'common.php';

// base
require_once 'dom_reader.class.php';

// for extracting seasons & episodes
require_once 'tvshows.class.php';

class LibIMDB_FinderException	extends	LibIMDB_Exception {}

class LibIMDB_Finder			extends	LibIMDB_DOMReader
{
	/*
	 *	FILTERING
	 *	===============================================
	 */

	/**
	 * Minimum Word Length (If below, word is removed)
	 * @var unknown_type
	 */
	const MIN_WORD_LENGTH				=	2;

	/**
	 * List of useless words to filter out...
	 *
	 * @var array
	 */
	static private $useless_words		=	array();

	/**
	 * List of useless regexp filter,
	 * for extended junk-filtering
	 *
	 * @var array
	 */
	static private $useless_words_pre	=	array();

	/*
	 *	MASK
	 *	===============================================
	 */

	/**
	 * Regex for matching a mask
	 *
	 * @var string
	 */
	const MASK_REGEX			=	'/\(([a-z]+)\)/i';

	/**
	 * Argument for mask()
	 *
	 * @var bool true
	 */
	const MASK_INT				=	true;

	/**
	 * Argument for mask()
	 *
	 * @var bool false
	 */
	const MASK_COMPLEX			=	false;

	/*
	 * Masks for use
	 */
	const MASK_OPT_NONE			=	0;
	const MASK_OPT_VIDEO		=	1;
	const MASK_OPT_VIDEO_GAME	=	2;

	/**
	 * Enable Mask?
	 *
	 * @var bool
	 */
	public $mask_enabled		=	false;

	/**
	 * Mask
	 *
	 * @var mixed
	 */
	private $mask;

	/*
	 *	INTERNAL
	 *	===============================================
	 */

	/**
	 * attribute for valid titles (xpath)
	 *
	 * @var string
	 */
	const TITLE_QUERY_ATTR	=	'contains(@href, "title/tt")';

	/**
	 * List of proposals
	 *
	 * @var array
	 */
	private $proposals;

	/*
	 *	INPUT & OUTPUT
	 *	===============================================
	 */

	/**
	 * Title to search for
	 *
	 * @var string
	 */
	private $title			=	'';

	/**
	 * If true, enables usage of remove_junk()
	 * in get_title()
	 *
	 * @var bool
	 */
	private $title_lock		=	true;

	/**
	 * Is title a Video-Game?
	 *
	 * @var bool
	 */
	public $is_video_game	=	false;

	/**
	 * Is title a Video?
	 *
	 * @var bool
	 */
	public $is_video		=	false;

	/**
	 * Is title a Video-Game?
	 *
	 * @var bool
	 */
	public $is_tvshow		=	false;

	/**
	 * Storage for episodes (if neccessary)
	 *
	 * @var array|null
	 */
	public $episodes;

	/**
	 * Found IMDB ID
	 *
	 * @var int
	 */
	public $id				=	0;

	/*
	 *	SETUP
	 *	===============================================
	 */

	/**
	 * Constructor => Setup
	 *
	 * @param string $_title [out|in]
	 * @return void
	 */
	public function __construct(&$_title)
	{
		$this -> title	=	$_title;
	}

	/**
	 * Returns Title
	 * 
	 * NOTE: return-by-reference
	 * UGLY HACK :-( => Don't use often
	 *
	 * @return string [ref]
	 */
	protected function & get_title()
	{
		// if first time => remove junk
		if($this	 ->	title_lock)
		{
			$this	 ->	remove_junk($this -> title, true);
			$this	 ->	title_lock	=	false;
		}

		return $this ->	title;
	}

	/**
	 * Force as TVShow (find out episodes/seasons)
	 *
	 * @return void
	 */
	public function as_tvshow()
	{
		// make sure {is_tvshow} = true
		if(!$this -> is_tvshow)
		{
			$this -> is_tvshow	=	true;
		}

		// find out
		$teller				=	new LibIMDB_TVShows($this -> title);
		$this -> episodes	=	$teller -> tell_episodes();
	}

	/**
	 * Fail
	 *
	 * @return void
	 * @throws object {@see LibIMDB_FinderException}
	 */
	protected function fail()
	{
		throw new LibIMDB_FinderException;
	}

	/**
	 * Load XML & Setup xPath
	 *
	 * @return void
	 * @throws object {@see LibIMDB_FinderException}
	 */
	protected function load()
	{
		parent :: load('http://www.imdb.com/find?s=tt&q='.urlencode($this -> get_title()));
	}

	/*
	 *	SEARCH / FIND / FILTER
	 *	===============================================
	 */

	/**
	 * Is Valid ID?
	 *
	 * @return bool true if valid
	 */
	protected function is_valid_id()
	{
		return	!empty($this -> id);
	}

	/**
	 * Search for IMDB-titles
	 * - Get Info-Blocks
	 *
	 * @param array $_blocks [out]
	 * @return bool false => direct / true => indirect
	 * @throws object {@see LibIMDB_FinderException}
	 */
	protected function search(&$_blocks)
	{
		/*
		 * Load Data
		 */
		$this -> load();

		/*
		 * Found Indirect?
		 */
		$this -> id = $this -> parse_id($this -> xpath -> evaluate
		(
			'//link[@rel="canonical" and '.self :: TITLE_QUERY_ATTR.']') -> item(0)
		);

		if($this -> is_valid_id())
		{
			return false;
		}

		/*
		 * Find Indirect
		 */
		$_blocks	=&	$this -> xpath -> evaluate('//table[.//@class="media_strip_thumbs" = 0]');

		// no matches? => fail
		if($_blocks	->	length < 1)
		{
			$this	->	fail();
		}

		return true;
	}

	/**
	 * Parse Info-Blocks
	 *
	 * @param array $_blocks [in]
	 * @param object $_xpath {@see DOMXPath}
	 * @return void
	 */
	protected function parse_blocks(&$_blocks)
	{
		// Block
		$ordinal_block		=	1;

		foreach($_blocks											as	$set)
		{
			// Set
			$ordinal_set	=	1;

			foreach($this -> xpath -> evaluate('.//td[3]', $set)	as	$title)
			{
				// mask?
				if($this -> no_mask())
				{
					// no	-	ignore mask;
					// info { imdb_id, name }
					$info	=	$this -> parse_link($title, true, '['.self :: TITLE_QUERY_ATTR.']');

					// is a title?
					if($info === null)
					{
						// nope;
						failed:
							continue;
					}
				}
				else
				{
					// yes	-	depends on mask;
					$info	=	$this -> query_anchor($title, '['.self :: TITLE_QUERY_ATTR.']') -> item(0);

					// is a title? || fails mask?
					if($info === null || $this -> fails_mask($info -> nextSibling -> nodeValue))
					{
						// nope;
						goto failed;
					}

					// info { imdb_id, name }
					$info	=	$this -> parse_link($info);
				}

				$this	->	remove_junk($info -> text);

				/*
				 *	O = S * B^1.5						 Ordinality
				 *	[---------------------------------------------]
				 *		S = Set		Ordinal	(Index)
				 *		B = Block	Ordinal	(Index)
				 *	[---------------------------------------------]
				 */
				$info	->	score		=	$ordinal_set * pow($ordinal_block, 1.5);

				$this	->	proposals[]	=	$info;

				$ordinal_set	++;
			}

			$ordinal_block		++;
		}

		// unset DOM stuff
		unset($this -> dom, $this -> xpath);
	}

	protected function get_words(&$_words, &$_count, &$_text)
	{
		$_words		=	array();
		$buffer		=	'';
		$_count		=	0;

		for($i = 0; $i < mb_strlen($_text); $i++)
		{
			if($_text[$i] === ' ')
			{
				$_words[]	=	$buffer;
				$buffer		=	'';
				$_count++;
			}
			else
			{
				$buffer		.=	$_text[$i];
			}
		}

		$_words[]	=	$buffer;
		$_count++;
	}

	/**
	 * Compute the scores (relevance) of titles
	 *
	 * @return void
	 */
	protected function compute_scores()
	{
		// title		words & count
		$this -> get_words($title_words, $title_count, $this -> title);

		// filter
		foreach($this -> proposals	as	$index	=>	&$proposal)
		{
			/*
			 * Compute Matches
			 */

			// proposal	words & count
			$this -> get_words($proposal_words, $proposal_count, $proposal -> text);

			// positive	matches
			$pos_matches		=	0;

			foreach($title_words	as	$word)
			{
				if(in_array($word, $proposal_words))
				{
					$pos_matches++;
				}
			}

			// drop if no positive matches
			if($pos_matches		==	0)
			{
				goto remove;
			}

			// highest count {from: title or proposal}
			if($title_count		>	$proposal_count)
			{
				$highest_count	=&	$title_count;
			}
			else
			{
				$highest_count	=&	$proposal_count;
			}

			// negative matches
			$neg_matches		=	$highest_count - $pos_matches;

			// concistency
			$range_count		=	abs($title_count - $proposal_count) + 1;
			$consistency		=	($neg_matches - $pos_matches / $highest_count) * $range_count;

			// too inconcistent?
			if($highest_count * 1.4		<	$consistency)
			{
				goto remove;
			}

			// match value
			$score		=&	$proposal -> score;
			$comp_1		=	20 - $consistency;
			$comp_2		=	$comp_1 / 4;
			$score		=	pow($pos_matches * $comp_1, $comp_2 < 1 ? 1 : $comp_2) / $score;

			if($proposal_count === $pos_matches		&&	($range_count === 1 || $range_count === 2))
			{
				$score	=	$score * 10 * $pos_matches * $range_count;
			}

			// too low score... remove
			if($score	<	1000)
			{
				remove:
					$this -> remove($index);
					continue;
			}
		}
	}

	/**
	 * Find IMDB-ID
	 *
	 * Formula For Score:
	 *
	 *	S = M / O
	 * [======================================================]
	 *		U = N Positive Matches
	 *		[----------------------]
	 *			U = 0	=>	remove()
	 *		[----------------------]
	 *
	 *		D = N Negative Matches
	 *
	 *		T = Title	 Word Count
	 *		P = Proposal Word Count
	 *		H = Highest	 Word Count
	 *		[----------------------]
	 *			T > P	=>	H = T
	 *			P > T	=>	H = P
	 *		[----------------------]
	 *
	 *		R = |T - P| + 1  (Range)
	 *
	 *		M = (U + Z) ^ X					Match
	 *		[-------------------------------------------]
	 *			C = (D - U / H) * R		Consistency
	 *			[----------------------------------]
	 *				H * 1.4 > 1		=>	remove()
	 *			[----------------------------------]
	 *
	 *			Z = 20 - C
	 *			X = Z / 4				Balancer
	 *			[-------------------------------]
	 *				X < 1	=>	X = 1
	 *			[-------------------------------]
	 *		[-------------------------------------------]
	 *
	 *		O = S * B^1.5					Ordinality
	 *		[-------------------------------------------]
	 *			S = Set		Ordinal	(Index)
	 *			B = Block	Ordinal	(Index)
	 *		[-------------------------------------------]
	 *
	 *		U = P,	R = {1, 2}	=>	Near Range
	 *		[---------------------------------]
	 *			S = S * 10 * U / R
	 *		[---------------------------------]
	 *
	 *		S < 1000	=>	remove()
	 * [======================================================]
	 *
	 * @return void
	 */
	public function find()
	{
		/*
		 *	Search
		 *	-	get via http
		 *	-	if returns
		 *		=>	true	=>	filter
		 *		=>	false	=>	skip (got ID)
		 */
		if($this	->	search($blocks))
		{
			/*
			 * Compute Scores, etc.
			 */
			$this	->	parse_blocks	($blocks);

			$this	->	fail_if_empty	();

			$this	->	compute_scores	();

			$this	->	fail_if_empty	();

			/*
			 * Re-Order
			 */
			usort($this -> proposals, function(&$_a, &$_b)
			{
				return	$_a -> score == $_b -> score ? 0 : ($_a -> score > $_b -> score	? -1 : 1);
			});

			/*
			 * The One and Only...
			 */
			$this	->	id	=	$this -> proposals[0] -> id;

			unset($this -> proposals);
		}

		// failure	=>	didn't get ID
		if(!$this	->	is_valid_id())
		{
			$this	->	fail();
		}
	}

	/**
	 * Fail if no proposals available
	 *
	 * @return void
	 * @throws object {@see LibIMDB_FinderException}
	 */
	protected function fail_if_empty()
	{
		if(empty($this -> proposals))
		{
			$this -> fail();
		}
	}

	/**
	 * Remove proposal
	 *
	 * @param int $_index
	 * @return void
	 */
	protected function remove(&$_index)
	{
		unset($this -> proposals[$_index]);
	}

	/**
	 * Remove junk from string
	 *
	 * @param string $_string [out] text to filter in
	 * @param bool $_extended
	 * @return void
	 */
	protected function remove_junk(&$_string, $_extended = false)
	{
		/*
		 * if not populated, populate list of useless words
		 */
		if(empty(self :: $useless_words))
		{
$lang_list	=	<<<END
			swe							|	# swedish
			eng							|	# english
			ita							|	# italian
			nl							|	# dutch
			fr[ae]?							|	# french
			spa							|	# spanish
			es							|
			bo?s							|	# bosnian
			p(t|or)							|	# portuguese
			bg							|	# bulgarian
			gre							|	# greek
			dan?							|	# danish
			fin							|	# finnish
			estsub							|	# estonian
			ice							|	# icelandic
			in?d							|	# indonesian
			heb?							|	# hebrew
			ara							|	# arabic
			jpn							|	# japaneese
			nor?							|	# norwegian (and no-subtitles)
			ro							|
			ru							|	# russian
			tr							|	# turkish
			ger							|	# german
			pl							|	# polish
			hindi							
END;

$common_regex	=	<<<END
		(hd|tv|dvb|sat|vhs)[-\s]?rip						|	# Leftover RIPs
		(
{$lang_list}|

			multi
		)[-\s]sub[s]?								|	# Subtitles
		tele(sync|cine)								|
		(no[-\s]?)?rar								|	# rar, norar
		(multi[-\s]?cam?)							|
		(720|1080)(i|p)								|	# 720p	...
		(h|p)dtv								|	# HDTV / PDTV
		mpe?g[-\s]?[1-4]							|	# MPEG
		workprint								|
		readnfo									|
		hdclassics								|
		screener								|
		5[.\s]1ch								|
		ac3(\(dd(\s?\d\.\d)?\))?						|
		dd\d\.\d								|
		avc(hd)?								|
		vol\.\d{1,2}								|
		high\s?quality								|
		music\s?video								|
		eztv										|
END;

$extended_common_regex	=	<<<END
		#	PRIMARY CAPTURERS

		mkv  | divx | xvid | avi | mp[3-4] | [hx]264 |
		flac | ogg  | aac  | dts | dolby   | \w+hd   |
		6ch  | kbps | zip  | iso | ntsc    | [cs]vcd |
		r5   | cam  | wp   | ts  | dxva    | proper  |
		scr  | h[dq]| ddc  | wmw | secam   | pal(-(b|g|d|k|i|m|l|nc?))?     |
		ipod |iph?one| zune | psp | ps[2-3] | (fl|[mf]4)v |

{$common_regex}
		ts(\.xvid\.\w+)?							|
		dvd										# DVD
		(
			scr(eener)?						|
			[-\s]?		(rip|ram|rw2|d)				|
			[-+\s]?		rw?	(\s?dl)?			|
			s							|
			\d
		)?									|
		cd										# CD, Extended Mode
		(
			[-\s]?		(rip|rom|rw?)				|
			\+g
		)?									|
		b										# BluRay, Extended Mode
		(
			d[-\s]?		(rip|re?)				|
			r[-\s]?		rip					|
			lu[-\s]?ray([-\s]?rip)?
		)
END;

			self :: $useless_words_pre	=	array
			(
'WiNetwork-bt',
'/crazy[-.]torrent/i',

' $1 '	=>	'/\((\d+)[.\-+][^\W_]+\)/',

'{
	[\[\(]											# begin:	(	[
	([^\W_]*[+.-\s])*									# optional

	\d*
	(
		#	Internet TLDs

		org | com | net | info | tv | biz | edu | mil | gov | bt |

		#	Special

		\{a-z\}   | \w*torrent	|

		#	LANGUAGES
		(
'.$lang_list.'
		)									|
		greek | arabic | bengal[ia]						|
		urdu  | malay								|
		(francai|nederland)s							|
		(hind|punjab|[fp]ars|hindustan)i					|
		(mandari|((arab|(per|malay)s|russ|(beng|it)al)i|germ)a)n		|
		(fren|dut)ch								|
		(japan|portugu|chin)ese							|
		(dan|swed|span|engl|turk|pol)ish 					|

'.$extended_common_regex.'
	)
	\d*

	([+.-\s][^\W_]*)*									# optional
	($|[\]\)])										# end:		)	]
}xi',

' $2 '	=>	'{
	\d*
	(
		(?i)
		#	case-insensitive
'.$extended_common_regex.'
	)
	\d*
	([+.-]\d+)?				# optional .\d*.
	[+.-\s]
	(
		#	RELEASE GROUPS

		MAX		|	PrisM		|	CAMELOT			|
		BiA		|	RaDiuS		|	DiAMOND			|
		FQM		|	M00DY		|	ARiGOLD			|
		FxM		|	METiS		|	SecretMyth		|
		2HD		|	DEViSE		|	MAXSPEED		|
		SYS		|	VoMiT		|	DaRkFib3r		|
		GFW		|	SiLENT		|	ExtraScene		|
		V2		|	CATCH		|	Skvaguratet		|
		LAP		|	ViSiON		|	Regenzy			|
		NiN		|	NoName		|	DivxMonkey		|
		LW		|	nEHAL		|	Megaplay		|
		TRL		|	NeDiVx		|	IMAGiNE			|
		3Li		|	Gopo		|	PROJECT1		|
		CPY		|	iMBT		|	AMIABLE			|
		TEA		|	ARROW 		|	APOCALYP		|
		TLF		|	BeStDivX	|	CiNEFiLE		|
		RED		|	SiNNERS		|	NhaNc3			|
		fov		|	TEAM		|	IMMERSE			|
		GFM		|	WiKi		|	NEPTUNE(\(Murlok\))?	|
		FoV		|	CtrlHD		|	La(nza(Mp(3\.CoM)?)?)?	|
		CBGB		|	ORENJi		|	PerfectionHD		|
		AToM		|	\*?FROSTY\*?|
		LOL
	)
}x',

'{
	(
		#	PRIMARY CAPTURERS

'.$common_regex.'
		\d?dvd										# DVD
		(
			scr(eener)?						|
			[-\s]?		(rip|ram|rw2|d)				|
			[-+\s]?		rw?	(\s?dl)?			|
			s							|
			\d
		)?									|
		\d?cd										# CD
		(
			[-\s]?		(rip|rom)				|
			\+g							|
			-rw?							|
			\d
		)									|
		b										# BluRay
		(
			d
			(
				[-\s]?	rip				|
				-re?
			)							|
			r[-\s]?rip						|
			lu[-\s]?ray([-\s]?rip)?
		)									|
		((?-i)										# CAPSLOCK ONLY
			P(ROPER|AL)						|
			TS							|
			H[QD]							|
			ZIP							|
			WP							|
			SCR							|
			CAM							|
			WEB[-.\s]?DL						|
			[CB]D\s?RE?						|
			UNRATED
		)									|
		\*uncensored\*
	)
}xi'
			);

			/*
			 *	Word	=>	1
			 */
			self :: $useless_words	=	array
			(
				'mkv'	=>	1,	'divx'	=>	1,	'xvid'	=>	1,	'avi'	=>	1,	'mp4'	=>	1,
				'h264'	=>	1,	'x264'	=>	1,	'svcd'	=>	1,	'r5'	=>	1,	'm4v'	=>	1,
				'mp3'	=>	1,	'flac'	=>	1,	'ogg'	=>	1,	'aac'	=>	1,	'f4v'	=>	1,
				'dolby'	=>	1,	'dts'	=>	1,	'6ch'	=>	1,	'kbps'	=>	1,	'flv'	=>	1,
				'iso'	=>	1,	'ntsc'	=>	1,	'cvcd'	=>	1,	'dxva'	=>	1,	'secam' =>	1,
				'ipod'	=>	1,	'iphone'=>	1,	'zune'	=>	1,	'psp'	=>	1,	'ps3'	=>	1,
				'ps2'	=>	1,
			);
		}

		/*
		 * First Pass... Pre-Filter Useless shit
		 */
		if($_extended)
		{
			foreach(self :: $useless_words_pre as $replace => $filter)
			{
				$replace = is_string($replace) ? $replace : ' ';
	
				if(empty($filter[0]))
				{
					continue;
				}
				else if($filter[0] == '/' || $filter[0] == '{')
				{
					$_string = preg_replace($filter, $replace, $_string);
				}
				else
				{
					$_string = str_ireplace($filter, $replace, $_string);
				}
			}
		}

		/*
		 * 1. Remove unwanted symbols
		 * 2. Remove multiple-subsequent-whitespaces & whitespace-non-space
		 * 3. Trim, strtolower
		 * 4. Explode, Apply, Join
		 *	  1. Remove too short words
		 *	  2. Remove useless words
		 */
		$_string = explode(' ', mb_strtolower(trim
		(
			preg_replace
			(
				'/\s{2,}|[^\S ]/',
				' ',
				preg_replace("/[\W_]/",' ', $_string)
			)
		), 'UTF-8'));

		foreach($_string as $index => &$str)
		{
			// too short?
			if(mb_strlen($str, 'UTF-8') < self::MIN_WORD_LENGTH)
			{
				// not a number?
				if(!preg_match('/\d/', $str))
				{
					goto remove;
				}
			}
			else if($_extended && isset(self::$useless_words[$str]))
			{
				// remove useless words...
				remove:
					unset($_string[$index]);
			}
		}

		$_string = join(' ', $_string);
	}

	/*
	 *	MASK
	 *	===============================================
	 */

	/**
	 * Test if hasn't mask
	 *
	 * @return bool
	 */
	protected function no_mask()
	{
		return $this -> mask(self :: MASK_INT) === self :: MASK_OPT_NONE;
	}

	/**
	 * Fails to Match Mask?
	 *
	 * @return bool
	 */
	protected function fails_mask($_source)
	{
		// get mask
		if(preg_match(self :: MASK_REGEX, $_source, $matches))
		{
			// we have a match; pull mask
			$mask	=	strtoupper($matches[1]);
		}
		else
		{
			// no match; set mask to none
			$mask	=	'';
		}

		// junk
		unset($matches);

		/*
		 * Try Masks
		 */
		$fail			=	false;

		$filter			=&	$this -> mask(self :: MASK_COMPLEX);

		// if first time	=>	convert to array
		if(is_string($filter))
		{
			$filter		=	explode(',', $filter);
		}

		foreach($filter as &$filter_part)
		{
			// if first time	=>	convert / test_mask	/ to array
			if(is_string($filter_part))
			{
				// exclusion or inclusion?
				$exclusion			=	$filter_part[0] === '!';

				if($exclusion)
				{
					$filter_part	=	ltrim($filter_part, '!');
				}

				$filter_part		=	array($exclusion, strtoupper($filter_part));
			}

			$has		=	$mask === $filter_part[1];

			if(($filter_part[0] && $has) || (!$filter_part[0] && !$has))
			{
				$fail	=	true;
				break;
			}
		}

		return $fail;
	}

	/**
	 * Return Mask
	 *
	 * @param bool $_as how to represent mask? see constants
	 * @return int|string depends on $_as
	 */
	public function & mask($_as = self :: MASK_INT)
	{
		$mask	=&	$this -> mask;

		// INIT Mask
		if(is_null($mask))
		{
			// Mask enabled?
			if($this -> mask_enabled)
			{
				if($this -> is_video_game)
				{
					$mask		=	array
					(
						'int'		=>	self :: MASK_OPT_VIDEO_GAME,
						'complex'	=>	'VG'
					);
				}
				else
				{
					$mask		=	array
					(
						'int'		=>	self :: MASK_OPT_VIDEO,
						'complex'	=>	'!VG'
					);
				}
			}
			// Mask disabled
			else
			{
				$mask		=	array
				(
					'int'		=>	self :: MASK_OPT_NONE,
					'complex'	=>	null
				);
			}
		}

		if($_as === self :: MASK_INT)
		{
			return $mask['int'];
		}
		else
		{
			return $mask['complex'];
		}
	}
}
?>