<?php
/**
 * This file implements the AbstractImport class designed to handle any kind of Imports.
 *
 * This file is part of the b2evolution/evocms project - {@link http://b2evolution.net/}.
 * See also {@link https://github.com/b2evolution/b2evolution}.
 *
 * @license GNU GPL v2 - {@link http://b2evolution.net/about/gnu-gpl-license}
 *
 * @copyright (c)2003-2019 by Francois Planque - {@link http://fplanque.com/}.
*
 * @license http://b2evolution.net/about/license.html GNU General Public License (GPL)
 *
 * @package evocore
 */
if( !defined('EVO_MAIN_INIT') ) die( 'Please, do not access this page directly.' );


/**
 * Abstract Import Class
 *
 * @package evocore
 */
class AbstractImport
{
	var $import_code;
	var $coll_ID;
	var $log_file = true;

	/**
	 * Get collection
	 *
	 * @param object|NULL|FALSE Collection
	 */
	function & get_Blog()
	{
		$BlogCache = & get_BlogCache();
		$Blog = & $BlogCache->get_by_ID( $this->coll_ID );

		return $Blog;
	}


	/**
	 * Start to log into file on disk
	 */
	function start_log()
	{
		global $baseurl, $media_path, $rsc_url, $app_version_long;

		// Get file path for log:
		$log_file_path = $media_path.'import/logs/'
			// Current data/time:
			.date( 'Y-m-d-H-i-s' ).'-'
			// Site base URL:
			.str_replace( '/', '-', preg_replace( '#^https?://#i', '', trim( $baseurl, '/' ) ) ).'-'
			// Collection short name:
			.( $import_Blog = & $this->get_Blog() ? preg_replace( '#[^a-z\d]+#', '-', strtolower( $import_Blog->get( 'shortname' ) ) ).'-' : '' )
			// Suffix for this import tool:
			.$this->import_code.'-import-log-'
			// Random hash:
			.generate_random_key( 16 ).'.html';

		// Try to create folder for log files:
		if( ! mkdir_r( $media_path.'import/logs/' ) )
		{	// Display error if folder cannot be created for log files:
			$this->display_log_file_error( 'Cannot create the folder <code>'.$media_path.'import/logs/</code> for log files!' );
			return false;
		}

		if( ! ( $this->log_file_handle = fopen( $log_file_path, 'w' ) ) )
		{	// Display error if the log fiel cannot be created in the log folder:
			$this->display_log_file_error( 'Cannot create the file <code>'.$log_file_path.'</code> for current log!' );
			return false;
		}

		// Display where log will be stored:
		echo '<b>Log file:</b> <code>'.$log_file_path.'</code><br />';

		// Write header of the log file:
		$this->log_to_file( '<!DOCTYPE html>'."\r\n"
			.'<html lang="en-US">'."\r\n"
			.'<head>'."\r\n"
			.'<link href="'.$rsc_url.'css/bootstrap/bootstrap.css?v='.$app_version_long.'" type="text/css" rel="stylesheet" />'."\r\n"
			.'<link href="'.$rsc_url.'build/bootstrap-backoffice-b2evo_base.bundle.css?v='.$app_version_long.'" type="text/css" rel="stylesheet" />'."\r\n"
			.'</head>'."\r\n"
			.'<body>' );
	}


	/**
	 * Display error when log cannot be stored in file on disk
	 *
	 * @param string Message
	 */
	function display_log_file_error( $message )
	{
		if( empty( $this->log_file_error_reported ) )
		{	// Report only first detected error to avoid next duplicated errors on screen:
			echo '<p class="text-danger"><span class="label label-danger">ERROR</span> '.$message.'</p>';
			$this->log_file_error_reported = true;
		}
	}


	/**
	 * End of log into file on disk
	 */
	function end_log()
	{
		// Write footer of the log file:
		$this->log_to_file( '</body>'."\r\n"
			.'</html>' );

		if( isset( $this->log_file_handle ) && $this->log_file_handle )
		{	// Close the log file:
			fclose( $this->log_file_handle );
		}
	}


	/**
	 * Log a message on screen and into file on disk
	 *
	 * @param string Message
	 * @param string Type: 'success', 'error', 'warning'
	 */
	function log( $message, $type = NULL )
	{
		if( $message === '' )
		{	// Don't log empty strings:
			return;
		}

		switch( $type )
		{
			case 'success':
				$before = '<p class="text-success"> ';
				$after = '</p>';
				break;

			case 'error':
				$before = '<p class="text-danger"><span class="label label-danger">ERROR</span> ';
				$after = '</p>';
				break;

			case 'warning':
				$before = '<p class="text-warning"><span class="label label-warning">WARNING</span> ';
				$after = '</p>';
				break;

			default:
				$before = '';
				$after = '';
				break;
		}

		$message = $before.$message.$after;

		// Display message on screen:
		echo $message;
		evo_flush();

		// Try to store a message into the log file on the disk:
		$this->log_to_file( $message );
	}


	/**
	 * Log SUCCESS message on screen and into file on disk
	 *
	 * @param string Message
	 */
	function log_success( $message )
	{
		$this->log( $message, 'success' );
	}


	/**
	 * Log ERROR message on screen and into file on disk
	 *
	 * @param string Message
	 */
	function log_error( $message )
	{
		$this->log( $message, 'error' );
	}


	/**
	 * Log WARNING message on screen and into file on disk
	 *
	 * @param string Message
	 */
	function log_warning( $message )
	{
		$this->log( $message, 'warning' );
	}


	/**
	 * Log a message into file on disk
	 *
	 * @param string Message
	 */
	function log_to_file( $message )
	{
		if( ! $this->log_file )
		{	// Don't log into file:
			return;
		}

		if( ! isset( $this->log_file_handle ) || ! $this->log_file_handle )
		{	// Log must be started:
			$this->display_log_file_error( 'You must start log by function <code>'.get_class( $this ).'->start_log()</code>!' );
			return;
		}

		// Put a message into the log file on the disk:
		fwrite( $this->log_file_handle, $message."\r\n" );
	}
}
?>