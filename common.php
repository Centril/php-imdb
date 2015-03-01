<?php
class LibIMDB_Exception extends \Exception	{}

class LibIMDB_ENUM
{
	const KEY_PERSON			=	1;
	const KEY_COMPANY			=	2;
	const KEY_CHARACTER			=	3;

	const TRAIT_GENRE			=	1;
	const TRAIT_PLOT_KEYWORD	=	2;
	const TRAIT_COUNTRY			=	3;
	const TRAIT_SOUND_MIX		=	4;
	const TRAIT_CERTIFICATION	=	5;
	const TRAIT_SOUNDTRACK		=	6;

	const PERSON_DIRECTOR		=	1;
	const PERSON_WRITER			=	2;
	const PERSON_PLAYER			=	3;
	const PERSON_PRODUCER		=	4;
	const PERSON_ORG_MUSIC		=	5;
	const PERSON_SOUNDTRACK		=	6;

	const EPISODE_FULL			=	true;
}
?>