<?php
abstract class LibIMDB_DOMReader
{
	/*
	 *	INTERNAL
	 *	===============================================
	 */

	/**
	 * Debugging ?
	 *
	 * @var bool
	 */
	const DEBUG		=	false;

	/**
	 * Steam Context
	 * - Result from steam_context_create();
	 *
	 * @var null|resource
	 */
	private static $steam_context	=	null;

	/**
	 * DOMDocument
	 *
	 * @var object {@see DOMDocument}
	 */
	protected $dom;

	/**
	 * XPath
	 *
	 * @var object {@see DOMXPath}
	 */
	protected $xpath;

	/*
	 *	Loading / Fetching
	 *	===============================================
	 */

	/**
	 * Fail: Prototype
	 *
	 * @return void
	 */
	abstract protected function fail();

	/**
	 * Cleanup - Call when ALL is done
	 *
	 * @return void
	 */
	public static function clean_up()
	{
		unset(self::$steam_context);
	}

	/**
	 * Fetch HTML-Info from IMDB
	 *
	 * @param string $_content HTML-store
	 * @param string $_from where from?
	 * @return void
	 */
	protected function fetch_content(&$_content, &$_from)
	{
		if(is_null(self::$steam_context))
		{
			self::$steam_context	=	stream_context_create
			(
				array
				(
					'http'	=>	array
					(
						'header' =>
							"Accept-language: en\r\n".
							"Content-Type: text/xml; charset=utf-8\r\n"
					)
				)
			);
		}

		/*
		 * @TODO Remove @ Production
		 */
		if(self::DEBUG)
		{
			$cache_name		=	'cache/' . preg_replace('/\W+/', '', $_from) . '.html';

			if(file_exists($cache_name))
			{
				$_content	=	file_get_contents($cache_name);
			}
			else
			{
				$_content	=	file_get_contents($_from, false, self::$steam_context);
				file_put_contents($cache_name, $_content);
			}

			return;
		}

		$_content	=	file_get_contents($_from, false, self::$steam_context);
	}

	/**
	 * Load XML & Setup xPath
	 *
	 * @param string $_from from where?
	 * @return void
	 * @throws object depends on fail()
	 */
	protected function load($_from)
	{
		// create & load DOM
		$this	->	dom		=	new \DOMDocument;
		$this	->	fetch_content($content, $_from);

		// working?
		if(!@$this	->	dom	->	loadHTML($content))
		{
			$this	->	fail();
		}

		// xPath
		$this	->	xpath	=	new \DOMXPath($this -> dom);
	}

	/*
	 *	Reading / Parsing
	 *	===============================================
	 */

	/**
	 * Get file-name
	 *
	 * @param string $_file_name
	 * @return string
	 */
	protected function file_name($_file_name)
	{
		return end(explode('/', substr($_file_name, 0, -1)));
	}

	/**
	 * Parse ID
	 *
	 * @param object $_anchor {@see DOMElement}
	 * @return int ID
	 */
	protected function parse_id(&$_anchor)
	{
		return is_null($_anchor) ? null : (int) substr($this -> file_name($_anchor -> getAttribute('href')), 2);
	}

	/**
	 * Parse a Link
	 *
	 * @param object $_link {@see DOMElement}
	 * @param bool $_first query for anchor? [optional]
	 * @param string $_attr [optional] {@see query_anchor}
	 * @return object|null link-info or null on failure
	 */
	protected function parse_link(&$_link, $_first = false, $_attr = null)
	{
		if($_first)
		{
			$anchor		=&	$this -> query_anchor($_link, $_attr) -> item(0);

			if($anchor	===	null)
			{
				return null;
			}
		}
		else
		{
			$anchor		=&	$_link;
		}

		$link			=	new \stdClass;
		$link -> text	=	$anchor	-> nodeValue;
		$link -> id		=	$this	-> parse_id($anchor);

		return $link;
	}

	/**
	 * Query Anchor
	 *
	 * @param object $_in {@see DOMElement}
	 * @param string $_attr [optional]
	 * @return object {@see DOMNodeList}
	 */
	protected function query_anchor(&$_in, $_attr = null)
	{
		return $this -> xpath -> evaluate('.//a' . $_attr, $_in);
	}
}
?>