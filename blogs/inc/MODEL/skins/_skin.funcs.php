<?php
/**
 * This file implements evoSkins support functions.
 *
 * This file is part of the b2evolution/evocms project - {@link http://b2evolution.net/}.
 * See also {@link http://sourceforge.net/projects/evocms/}.
 *
 * @copyright (c)2003-2006 by Francois PLANQUE - {@link http://fplanque.net/}.
 * Parts of this file are copyright (c)2004-2005 by Daniel HAHLER - {@link http://thequod.de/contact}.
 *
 * @license http://b2evolution.net/about/license.html GNU General Public License (GPL)
 *
 * {@internal Open Source relicensing agreement:
 * Daniel HAHLER grants Francois PLANQUE the right to license
 * Daniel HAHLER's contributions to this file and the b2evolution project
 * under any OSI approved OSS license (http://www.opensource.org/licenses/).
 * }}
 *
 * @package evocore
 *
 * {@internal Below is a list of authors who have contributed to design/coding of this file: }}
 * @author blueyed: Daniel HAHLER.
 * @author fplanque: Francois PLANQUE.
 *
 * @version $Id$
 */
if( !defined('EVO_MAIN_INIT') ) die( 'Please, do not access this page directly.' );


/**
 * Template function: output HTML base tag to current skin
 */
function skin_base_tag()
{
	global $skins_url, $skin, $Blog;

	if( ! empty( $skin ) )
	{	
		$base_href = $skins_url.$skin.'/';
	}
	else
	{ // No skin used:
		if( ! empty( $Blog ) )
		{
			$base_href = $Blog->get( 'baseurl' );
		}
		else
		{
			$base_href = $baseurl;
		}
	}

	base_tag( $base_href );
}

 
/**
 * checks if a skin exists
 *
 * @return boolean true is exists, false if not
 * @param skin name (directory name)
 */
function skin_exists( $name, $filename = '_main.php' )
{
	return is_readable( get_path( 'skins' ).$name.'/'.$filename );
}


/**
 * Outputs an <option> set with default skin selected
 *
 * skin_options(-)
 *
 */
function skin_options( $default = '' )
{
	echo skin_options_return( $default );
}


/**
 * Returns an <option> set with default skin selected
 *
 * @return string
 */
function skin_options_return( $default = '' )
{
	$r = '';

	for( skin_list_start(); skin_list_next(); )
	{
		$r .= '<option value="';
		$r .= skin_list_iteminfo( 'name', false );
		$r .=  '"';
		if( skin_list_iteminfo( 'name',false ) == $default )
		{
			$r .= ' selected="selected" ';
		}
		$r .=  '>';
		$r .= skin_list_iteminfo( 'name', false );
		$r .=  "</option>\n";
	}

	return $r;
}


/**
 * Initializes skin list iterator
 *
 * lists all folders in skin directory
 */
function skin_list_start()
{
	global $skin_path, $skin_dir;

	$skin_path = get_path( 'skins' );
	$skin_dir = dir( $skin_path );
}


/**
 * Get next skin
 *
 * Lists all folders in skin directory,
 * except the ones starting with a . (UNIX style) or a _ (FrontPage style)
 *
 * @return string skin name
 */
function skin_list_next()
{
	global $skin_path, $skin_dir, $skin_name;

	do
	{ // Find next subfolder:
		if( !($skin_name = $skin_dir->read()) )
			return false;		// No more subfolder
	} while( ( ! is_dir($skin_path.'/'.$skin_name) )	// skip regular files
						|| ($skin_name[0] == '.')								// skip UNIX hidden files/dirs
						|| ($skin_name[0] == '_')								// skip FRONTPAGE hidden files/dirs
						|| ($skin_name == 'CVS' ) );						// Skip CVS directory
	// echo 'ret=',  $skin_name;
	return $skin_name;
}


/**
 * skin_list_iteminfo(-)
 *
 * Display info about item
 *
 * fplanque: created
 */
function skin_list_iteminfo( $what='', $display = true )
{
	global $skin_path, $skin_name;

	switch( $what )
	{
		case 'path':
			$info = $skin_path.'/'.$skin_name;

		case 'name':
		default:
			$info = $skin_name;
	}

	if( $display ) echo $info;

	return $info;
}


/**
 * skin_change_url(-)
 * @param boolean display (true) or return?
 */
function skin_change_url( $display = true )
{
	$r = url_add_param( get_bloginfo('blogurl'), 'skin='.rawurlencode(skin_list_iteminfo('name',false)) );
	if( $display )
	{
		echo $r;
	}
	else
	{
		return $r;
	}
}


/*
 * $Log$
 * Revision 1.4  2006/07/04 17:32:29  fplanque
 * no message
 *
 * Revision 1.3  2006/03/24 19:40:49  blueyed
 * Only use absolute URLs if necessary because of used <base/> tag. Added base_tag()/skin_base_tag(); deprecated skinbase()
 *
 * Revision 1.2  2006/03/12 23:08:59  fplanque
 * doc cleanup
 *
 * Revision 1.1  2006/02/23 21:11:58  fplanque
 * File reorganization to MVC (Model View Controller) architecture.
 * See index.hml files in folders.
 * (Sorry for all the remaining bugs induced by the reorg... :/)
 *
 * Revision 1.9  2005/12/12 19:21:23  fplanque
 * big merge; lots of small mods; hope I didn't make to many mistakes :]
 *
 * Revision 1.8  2005/11/24 16:51:08  blueyed
 * minor
 *
 * Revision 1.7  2005/11/18 22:26:07  blueyed
 * skin_exists(): check for readable filename (_main.php by default), instead of is_dir()
 *
 * Revision 1.6  2005/09/06 17:13:55  fplanque
 * stop processing early if referer spam has been detected
 *
 * Revision 1.5  2005/08/04 13:05:10  fplanque
 * bugfix
 *
 * Revision 1.4  2005/06/12 07:02:51  blueyed
 * Added skin_options_return()
 *
 * Revision 1.3  2005/02/28 09:06:33  blueyed
 * removed constants for DB config (allows to override it from _config_TEST.php), introduced EVO_CONFIG_LOADED
 *
 * Revision 1.2  2004/10/14 18:31:25  blueyed
 * granting copyright
 *
 * Revision 1.1  2004/10/13 22:46:32  fplanque
 * renamed [b2]evocore/*
 *
 * Revision 1.21  2004/10/12 18:48:34  fplanque
 * Edited code documentation.
 *
 */
?>
