<?php
/**
 * This file implements the functions to work with WordPress importer.
 *
 * b2evolution - {@link http://b2evolution.net/}
 * Released under GNU GPL License - {@link http://b2evolution.net/about/gnu-gpl-license}
 *
 * @license GNU GPL v2 - {@link http://b2evolution.net/about/gnu-gpl-license}
 *
 * @copyright (c)2003-2018 by Francois Planque - {@link http://fplanque.com/}
 *
 * @package admin
 */
if( !defined('EVO_MAIN_INIT') ) die( 'Please, do not access this page directly.' );


/**
 * Get data to start import from wordpress XML/ZIP file or from Item Type XML file
 *
 * @param string Path of XML/ZIP file
 * @param boolean TRUE to allow to use already extracted ZIP archive
 * @return array Data array:
 *                 'error' - FALSE on success OR error message,
 *                 'XML_file_path' - Path to XML file,
 *                 'attached_files_path' - Path to attachments folder,
 *                 'ZIP_folder_path' - Path of the extracted ZIP files.
 */
function wpxml_get_import_data( $XML_file_path, $allow_use_extracted_folder = false )
{
	// Start to collect all printed errors from buffer:
	ob_start();

	$XML_file_name = basename( $XML_file_path );
	$ZIP_folder_path = NULL;
	$zip_file_name = NULL;

	// Do NOT use first found folder for attachments:
	$use_first_folder_for_attachments = false;

	if( preg_match( '/\.(xml|txt)$/i', $XML_file_name ) )
	{	// XML format
		// Check WordPress XML file:
		wpxml_check_xml_file( $XML_file_path );
	}
	else if( preg_match( '/\.zip$/i', $XML_file_name ) )
	{	// ZIP format
		// Extract ZIP and check WordPress XML file
		global $media_path;

		$zip_file_name = $XML_file_name;

		$ZIP_folder_name = substr( $XML_file_name, 0, -4 );
		$ZIP_folder_path = $media_path.'import/'.$ZIP_folder_name;

		$zip_folder_exists = ( file_exists( $ZIP_folder_path ) && is_dir( $ZIP_folder_path ) );

		if( ! $allow_use_extracted_folder && $zip_folder_exists )
		{	// Don't try to extract into already existing folder:
			echo '<p class="text-danger">'.sprintf( 'The destination folder %s already exists. If you want to unzip %s again, delete %s first.',
					'<code>'.$ZIP_folder_path.'/</code>',
					'<code>'.$XML_file_name.'</code>',
					'<code>'.$ZIP_folder_path.'/</code>'
				).'</p>';
		}
		elseif( ( $allow_use_extracted_folder && $zip_folder_exists ) ||
		        unpack_archive( $XML_file_path, $ZIP_folder_path, true, $XML_file_name ) )
		{	// If we can use already extracted ZIP archive or it is unpacked successfully now:

			// Reset path and set only if XML file is found in ZIP archive:
			$XML_file_path = false;

			// Find valid XML file in ZIP package:
			$ZIP_files_list = scandir( $ZIP_folder_path );
			$xml_exists_in_zip = false;
			for( $i = 1; $i <= 2; $i++ )
			{	// Run searcher 1st time to find XML file in a root of ZIP archive
				// and 2nd time to find XML file in 1 level subfolders of the root:
				foreach( $ZIP_files_list as $ZIP_file )
				{
					if( $ZIP_file == '.' || $ZIP_file == '..' )
					{	// Skip reserved dir names of the current path:
						continue;
					}
					if( $i == 2 )
					{	// This is 2nd time to find XML file in 1 level subfolders of the root:
						if( is_dir( $ZIP_folder_path.'/'.$ZIP_file ) )
						{	// This is a subfolder, Scan it to find XML files inside:
							$ZIP_folder_current_path = $ZIP_folder_path.'/'.$ZIP_file;
							$ZIP_folder_files = scandir( $ZIP_folder_current_path );
						}
						else
						{	// Skip files:
							continue;
						}
					}
					else
					{	// This is a single file or folder:
						$ZIP_folder_files = array( $ZIP_file );
						$ZIP_folder_current_path = $ZIP_folder_path;
					}
					foreach( $ZIP_folder_files as $ZIP_file )
					{
						if( preg_match( '/\.(xml|txt)$/i', $ZIP_file ) )
						{	// XML file is found in ZIP package:
							$XML_file_path = $ZIP_folder_current_path.'/'.$ZIP_file;
							if( wpxml_check_xml_file( $XML_file_path ) )
							{	// XML file is valid:
								$xml_exists_in_zip = true;
								break 3;
							}
						}
					}
				}
			}
		}

		// Use first found folder for attachments when no reserved folders not found in ZIP before:
		$use_first_folder_for_attachments = true;
	}
	else
	{	// Unrecognized extension:
		echo '<p class="text-danger">'.sprintf( '%s has an unrecognized extension.', '<code>'.$xml_file['name'].'</code>' ).'</p>';
	}

	if( $XML_file_path )
	{	// Get a path with attached files for the XML file:
		$attached_files_path = get_import_attachments_folder( $XML_file_path, $use_first_folder_for_attachments );
	}
	else
	{	// Wrong source file:
		$attached_files_path = false;
	}

	if( isset( $xml_exists_in_zip ) && $xml_exists_in_zip === false && file_exists( $ZIP_folder_path ) )
	{	// No XML is detected in ZIP package:
		echo '<p class="text-danger">'.'Correct XML file is not detected in your ZIP package.'.'</p>';
		// Delete temporary folder that contains the files from extracted ZIP package:
		rmdir_r( $ZIP_folder_path );
	}

	// Get all printed errors:
	$errors = ob_get_clean();

	return array(
			'errors'               => empty( $errors ) ? false : $errors,
			'XML_file_path'        => $XML_file_path,
			'attached_files_path'  => $attached_files_path,
			'ZIP_folder_path'      => $ZIP_folder_path,
		);
}


/**
 * Import WordPress data from XML file into b2evolution database
 *
 * @param string Path of XML file
 * @param string|boolean Path of folder with attachments, may be FALSE if folder is not found
 * @param string|NULL Temporary folder of unpacked ZIP archive
 */
function wpxml_import( $XML_file_path, $attached_files_path = false, $ZIP_folder_path = NULL )
{
	global $DB, $tableprefix, $media_path;

	// Load classes:
	load_class( 'regional/model/_country.class.php', 'Country' );
	load_class( 'regional/model/_region.class.php', 'Region' );
	load_class( 'regional/model/_subregion.class.php', 'Subregion' );
	load_class( 'regional/model/_city.class.php', 'City' );

	// Set Blog from request blog ID
	$wp_blog_ID = get_param( 'wp_blog_ID' );
	$BlogCache = & get_BlogCache();
	$wp_Blog = & $BlogCache->get_by_ID( $wp_blog_ID );

	// The import type ( replace | append )
	$import_type = get_param( 'import_type' );
	// Should we delete files on 'replace' mode?
	$delete_files = get_param( 'delete_files' );
	// Should we try to match <img> tags with imported attachments based on filename in post content after import?
	$import_img = get_param( 'import_img' );
	// Item Types relations:
	$selected_item_type_names = param( 'item_type_names', 'array:integer' );
	$selected_item_type_usages = param( 'item_type_usages', 'array:integer' );
	$selected_item_type_none = param( 'item_type_none', 'integer' );

	$all_wp_attachments = array();

	// Parse WordPress XML file into array
	echo 'Loading & parsing the XML file...'.'<br />';
	evo_flush();
	$xml_data = wpxml_parser( $XML_file_path );
	echo '<ul class="list-default">';
		echo '<li>'.'Memory used by XML parsing (difference between free RAM before loading XML and after)'.': <b>'.bytesreadable( $xml_data['memory']['parsing'] ).'</b></li>';
		echo '<li>'.'Memory used by temporary arrays (difference between free RAM after loading XML and after copying all the various data into temporary arrays)'.': <b>'.bytesreadable( $xml_data['memory']['arrays'] ).'</b></li>';
	echo '</ul>';
	evo_flush();

	$DB->begin();

	if( $import_type == 'replace' )
	{ // Remove data from selected blog

		// Get existing categories
		$SQL = new SQL( 'Get existing categories of collection #'.$wp_blog_ID );
		$SQL->SELECT( 'cat_ID' );
		$SQL->FROM( 'T_categories' );
		$SQL->WHERE( 'cat_blog_ID = '.$DB->quote( $wp_blog_ID ) );
		$old_categories = $DB->get_col( $SQL );
		if( !empty( $old_categories ) )
		{ // Get existing posts
			$SQL = new SQL();
			$SQL->SELECT( 'post_ID' );
			$SQL->FROM( 'T_items__item' );
			$SQL->WHERE( 'post_main_cat_ID IN ( '.implode( ', ', $old_categories ).' )' );
			$old_posts = $DB->get_col( $SQL->get() );
		}

		echo 'Removing the comments... ';
		evo_flush();
		if( !empty( $old_posts ) )
		{
			$SQL = new SQL();
			$SQL->SELECT( 'comment_ID' );
			$SQL->FROM( 'T_comments' );
			$SQL->WHERE( 'comment_item_ID IN ( '.implode( ', ', $old_posts ).' )' );
			$old_comments = $DB->get_col( $SQL->get() );
			$DB->query( 'DELETE FROM T_comments WHERE comment_item_ID IN ( '.implode( ', ', $old_posts ).' )' );
			if( !empty( $old_comments ) )
			{
				$DB->query( 'DELETE FROM T_comments__votes WHERE cmvt_cmt_ID IN ( '.implode( ', ', $old_comments ).' )' );
				$DB->query( 'DELETE FROM T_links WHERE link_cmt_ID IN ( '.implode( ', ', $old_comments ).' )' );
			}
		}
		echo 'OK'.'<br />';

		echo 'Removing the posts... ';
		evo_flush();
		if( !empty( $old_categories ) )
		{
			$DB->query( 'DELETE FROM T_items__item WHERE post_main_cat_ID IN ( '.implode( ', ', $old_categories ).' )' );
			if( !empty( $old_posts ) )
			{ // Remove the post's data from related tables
				if( $delete_files )
				{ // Get the file IDs that should be deleted from hard drive
					$SQL = new SQL();
					$SQL->SELECT( 'DISTINCT link_file_ID' );
					$SQL->FROM( 'T_links' );
					$SQL->WHERE( 'link_itm_ID IN ( '.implode( ', ', $old_posts ).' )' );
					$deleted_file_IDs = $DB->get_col( $SQL->get() );
				}
				$DB->query( 'DELETE FROM T_items__item_settings WHERE iset_item_ID IN ( '.implode( ', ', $old_posts ).' )' );
				$DB->query( 'DELETE FROM T_items__prerendering WHERE itpr_itm_ID IN ( '.implode( ', ', $old_posts ).' )' );
				$DB->query( 'DELETE FROM T_items__subscriptions WHERE isub_item_ID IN ( '.implode( ', ', $old_posts ).' )' );
				$DB->query( 'DELETE FROM T_items__version WHERE iver_itm_ID IN ( '.implode( ', ', $old_posts ).' )' );
				$DB->query( 'DELETE FROM T_postcats WHERE postcat_post_ID IN ( '.implode( ', ', $old_posts ).' )' );
				$DB->query( 'DELETE FROM T_slug WHERE slug_itm_ID IN ( '.implode( ', ', $old_posts ).' )' );
				$DB->query( 'DELETE l, lv FROM T_links AS l
											 LEFT JOIN T_links__vote AS lv ON lv.lvot_link_ID = l.link_ID
											WHERE l.link_itm_ID IN ( '.implode( ', ', $old_posts ).' )' );
				$DB->query( 'DELETE FROM T_items__user_data WHERE itud_item_ID IN ( '.implode( ', ', $old_posts ).' )' );
			}
		}
		echo 'OK'.'<br />';

		echo 'Removing the categories... ';
		evo_flush();
		$DB->query( 'DELETE FROM T_categories WHERE cat_blog_ID = '.$DB->quote( $wp_blog_ID ) );
		echo 'OK'.'<br />';

		echo 'Removing the tags that are no longer used... ';
		evo_flush();
		if( !empty( $old_posts ) )
		{ // Remove the tags

			// Get tags from selected blog
			$SQL = new SQL();
			$SQL->SELECT( 'itag_tag_ID' );
			$SQL->FROM( 'T_items__itemtag' );
			$SQL->WHERE( 'itag_itm_ID IN ( '.implode( ', ', $old_posts ).' )' );
			$old_tags_this_blog = array_unique( $DB->get_col( $SQL->get() ) );

			if( !empty( $old_tags_this_blog ) )
			{
				// Get tags from other blogs
				$SQL = new SQL();
				$SQL->SELECT( 'itag_tag_ID' );
				$SQL->FROM( 'T_items__itemtag' );
				$SQL->WHERE( 'itag_itm_ID NOT IN ( '.implode( ', ', $old_posts ).' )' );
				$old_tags_other_blogs = array_unique( $DB->get_col( $SQL->get() ) );
				$old_tags_other_blogs_sql = !empty( $old_tags_other_blogs ) ? ' AND tag_ID NOT IN ( '.implode( ', ', $old_tags_other_blogs ).' )': '';

				// Remove the tags that are no longer used
				$DB->query( 'DELETE FROM T_items__tag
					WHERE tag_ID IN ( '.implode( ', ', $old_tags_this_blog ).' )'.
					$old_tags_other_blogs_sql );
			}

			// Remove the links of tags with posts
			$DB->query( 'DELETE FROM T_items__itemtag WHERE itag_itm_ID IN ( '.implode( ', ', $old_posts ).' )' );
		}
		echo 'OK'.'<br />';

		if( $delete_files )
		{ // Delete the files
			echo 'Removing the files... ';

			if( ! empty( $deleted_file_IDs ) )
			{
				// Commit the DB changes before files deleting
				$DB->commit();

				// Get the deleted file IDs that are linked to other objects
				$SQL = new SQL();
				$SQL->SELECT( 'DISTINCT link_file_ID' );
				$SQL->FROM( 'T_links' );
				$SQL->WHERE( 'link_file_ID IN ( '.implode( ', ', $deleted_file_IDs ).' )' );
				$linked_file_IDs = $DB->get_col( $SQL->get() );
				// We can delete only the files that are NOT linked to other objects
				$deleted_file_IDs = array_diff( $deleted_file_IDs, $linked_file_IDs );

				$FileCache = & get_FileCache();
				foreach( $deleted_file_IDs as $deleted_file_ID )
				{
					if( ! ( $deleted_File = & $FileCache->get_by_ID( $deleted_file_ID, false, false ) ) )
					{ // Incorrect file ID
						echo '<p class="text-danger">'.sprintf( 'No file #%s found in DB. It cannot be deleted.', $deleted_file_ID ).'</p>';
					}
					if( ! $deleted_File->unlink() )
					{ // No permission to delete file
						echo '<p class="text-danger">'.sprintf( 'Could not delete the file %s.', '<code>'.$deleted_File->get_full_path().'</code>' ).'</p>';
					}
					// Clear cache to save memory
					$FileCache->clear();
				}

				// Start new transaction for the data inserting
				$DB->begin();
			}

			echo 'OK'.'<br />';
		}

		echo '<br />';
	}


	/* Import authors */
	$authors = array();
	$authors_IDs = array();
	$authors_links = array();
	if( isset( $xml_data['authors'] ) && count( $xml_data['authors'] ) > 0 )
	{
		global $Settings, $UserSettings;

		echo '<p><b>'.'Importing users...'.' </b>';
		evo_flush();

		// Get existing users
		$SQL = new SQL();
		$SQL->SELECT( 'user_login, user_ID' );
		$SQL->FROM( 'T_users' );
		$existing_users = $DB->get_assoc( $SQL->get() );

		$new_users_num = 0;
		$skipped_users_num = 0;
		$failed_users_num = 0;
		foreach( $xml_data['authors'] as $author )
		{
			// Replace unauthorized chars of username:
			$author_login = preg_replace( '/([^a-z0-9_\-\.])/i', '_', $author['author_login'] );
			$author_login = utf8_substr( utf8_strtolower( $author_login ), 0, 20 );

			echo '<p>'.sprintf( 'Importing user: %s', '#'.$author['author_id'].' - "'.$author_login.'"' ).'... ';

			if( empty( $existing_users[ $author_login ] ) )
			{	// Insert new user into DB if User doesn't exist with current login name

				$GroupCache = & get_GroupCache();
				if( !empty( $author['author_group'] ) )
				{	// Set user group from xml data
					if( ( $UserGroup = & $GroupCache->get_by_name( $author['author_group'], false ) ) === false )
					{	// If user's group doesn't exist yet, we should create new
						$UserGroup = new Group();
						$UserGroup->set( 'name', $author['author_group'] );
						$UserGroup->dbinsert();
					}
				}
				else
				{	// Set default user group is it is not defined in xml
					if( ( $UserGroup = & $GroupCache->get_by_name( 'Normal Users', false ) ) === false )
					{	// Exit from import of users, because we cannot set default user group
						break;
					}
				}

				unset( $author_created_from_country );
				if( !empty( $author['author_created_from_country'] ) )
				{	// Get country ID from DB by code
					$CountryCache = & get_CountryCache();
					if( ( $Country = & $CountryCache->get_by_name( $author['author_created_from_country'], false ) ) !== false )
					{
						$author_created_from_country = $Country->ID;
					}
				}

				// Get regional IDs by their names
				$author_regions = wp_get_regional_data( $author['author_country'], $author['author_region'], $author['author_subregion'], $author['author_city'] );

				$User = new User();
				$User->set( 'login', $author_login );
				$User->set( 'email', trim( $author['author_email'] ) );
				$User->set( 'firstname', $author['author_first_name'] );
				$User->set( 'lastname', $author['author_last_name'] );
				$User->set( 'pass', $author['author_pass'] );
				$User->set( 'salt', $author['author_salt'] );
				$User->set( 'pass_driver', $author['author_pass_driver'] );
				$User->set_Group( $UserGroup );
				$User->set( 'status', !empty( $author['author_status'] ) ? $author['author_status'] : 'autoactivated' );
				$User->set( 'nickname', $author['author_nickname'] );
				$User->set( 'url', $author['author_url'] );
				$User->set( 'level', $author['author_level'] );
				$User->set( 'locale', $author['author_locale'] );
				$User->set( 'gender', ( $author['author_gender'] == 'female' ? 'F' : ( $author['author_gender'] == 'male' ? 'M' : '' ) ) );
				if( $author['author_age_min'] > 0 )
				{
					$User->set( 'age_min', $author['author_age_min'] );
				}
				if( $author['author_age_max'] > 0 )
				{
					$User->set( 'age_max', $author['author_age_max'] );
				}
				if( isset( $author_created_from_country ) )
				{	// User was created from this country
					$User->set( 'reg_ctry_ID', $author_created_from_country );
				}
				if( !empty( $author_regions['country'] ) )
				{	// Country
					$User->set( 'ctry_ID', $author_regions['country'] );
					if( !empty( $author_regions['region'] ) )
					{	// Region
						$User->set( 'rgn_ID', $author_regions['region'] );
						if( !empty( $author_regions['subregion'] ) )
						{	// Subregion
							$User->set( 'subrg_ID', $author_regions['subregion'] );
						}
						if( !empty( $author_regions['city'] ) )
						{	// City
							$User->set( 'city_ID', $author_regions['city'] );
						}
					}
				}
				$User->set( 'source', $author['author_source'] );
				$User->set_datecreated( empty( $author['author_created_ts'] ) ? time() : intval( $author['author_created_ts'] ) );
				$User->set( 'lastseen_ts', ( empty( $author['author_lastseen_ts'] ) ? NULL : $author['author_lastseen_ts'] ), true );
				$User->set( 'profileupdate_date', empty( $author['author_profileupdate_date'] ) ? date( 'Y-m-d H:i:s' ): $author['author_profileupdate_date'] );
				if( ! $User->dbinsert() )
				{	// Error on insert new user:
					$failed_users_num++;
					echo '<span class="text-danger">'.sprintf( 'User %s could not be inserted in DB.', '<code>'.$author_login.'</code>' ).'</span>';
					continue;
				}
				$user_ID = $User->ID;
				if( !empty( $user_ID ) && !empty( $author['author_created_fromIPv4'] ) )
				{
					$UserSettings->set( 'created_fromIPv4', ip2int( $author['author_created_fromIPv4'] ), $user_ID );
				}

				if( ! empty( $author['links'] ) )
				{	// Store user attachments in array to link them below after importing files:
					$authors_links[ $user_ID ] = array();
					foreach( $author['links'] as $link )
					{
						if( isset( $author['author_avatar_file_ID'] ) &&
						    $link['link_file_ID'] == $author['author_avatar_file_ID'] )
						{	// Mark this link as main avatar in order to update this with new inserted file ID below:
							$link['is_main_avatar'] = true;
						}
						$authors_links[ $user_ID ][ $link['link_file_ID'] ] = $link;
					}
				}

				$new_users_num++;
				echo '<span class="text-success">'.'OK'.'.</span>';
			}
			else
			{	// Get ID of existing user
				$user_ID = $existing_users[ $author_login ];
				echo '<span class="text-warning">'.sprintf( 'Skip because user already exists with same login and ID #%d.', intval( $user_ID ) ).'</span>';
				$skipped_users_num++;
			}
			// Save user ID of current author
			$authors[ $author_login ] = (string) $user_ID;
			$authors_IDs[ $author['author_id'] ] = (string) $user_ID;

			echo '</p>';
			evo_flush();
		}

		$UserSettings->dbupdate();

		echo '<b class="text-success">'.sprintf( '%d new users', $new_users_num ).'</b>';
		if( $skipped_users_num )
		{
			echo '<br /><b class="text-warning">'.sprintf( '%d skipped users', $skipped_users_num ).'</b>';
		}
		if( $failed_users_num )
		{
			echo '<br /><b class="text-danger">'.sprintf( '%d users could not be imported', $failed_users_num ).'</b>';
		}
		echo '</p>';
	}

	/* Import files, Copy them all to media folder */
	$files = array();
	if( isset( $xml_data['files'] ) && count( $xml_data['files'] ) > 0 )
	{
		echo '<p><b>'.'Importing the files...'.' </b>';
		evo_flush();

		if( ! $attached_files_path || ! file_exists( $attached_files_path ) )
		{	// Display an error if files are attached but folder doesn't exist:
			echo '<p class="text-danger">'.sprintf( 'No attachments folder %s found. It must exists to import the attached files properly.', ( $attached_files_path ? '<code>'.$attached_files_path.'</code>' : '' ) ).'</p>';
		}
		else
		{	// Try to import files from the selected subfolder:
			$files_count = 0;

			$UserCache = & get_UserCache();

			foreach( $xml_data['files'] as $file )
			{
				switch( $file['file_root_type'] )
				{
					case 'shared':
						// Shared files
						$file['file_root_ID'] = 0;
						break;

					case 'user':
						// User's files
						if( isset( $authors_IDs[ $file['file_root_ID'] ] ) )
						{ // If owner of this file exists in our DB
							$wp_user_ID = $file['file_root_ID'];
							$file['file_root_ID'] = $authors_IDs[ $file['file_root_ID'] ];
							break;
						}
						else
						{
							unset( $wp_user_ID );
						}
						// Otherwise we should upload this file into blog's folder:

					default: // 'collection', 'absolute', 'skins'
						// The files from other blogs and from other places must be moved in the folder of the current blog
						$file['file_root_type'] = 'collection';
						$file['file_root_ID'] = $wp_blog_ID;
						break;
				}

				// Source of the importing file:
				$file_source_path = $attached_files_path.$file['zip_path'].$file['file_path'];

				// Try to import file from source path:
				if( $File = & wpxml_create_File( $file_source_path, $file ) )
				{	// Store the created File in array because it will be linked to the Items below:
					$files[ $file['file_ID'] ] = $File;

					if( $import_img )
					{	// Collect file name in array to link with post below:
						$file_name = basename( $file['file_path'] );
						if( isset( $all_wp_attachments[ $file_name ] ) )
						{	// Don't use this file if more than one use same name, e.g. from different folders:
							$all_wp_attachments[ $file_name ] = false;
						}
						else
						{	// This is a first detected file with current name:
							$all_wp_attachments[ $file_name ] = $File->ID;
						}
					}

					if( $file['file_root_type'] == 'user' &&
					    isset( $authors_links[ $file['file_root_ID'] ], $authors_links[ $file['file_root_ID'] ][ $file['file_ID'] ] ) &&
					    ( $User = & $UserCache->get_by_ID( $file['file_root_ID'], false, false ) ) )
					{	// Link file to User:
						$link = $authors_links[ $file['file_root_ID'] ][ $file['file_ID'] ];
						$LinkOwner = new LinkUser( $User );
						if( ! empty( $link['is_main_avatar'] ) )
						{	// Update if current file is main avatar for the User:
							$User->set( 'avatar_file_ID', $File->ID );
							$User->dbupdate();
						}
						if( $File->link_to_Object( $LinkOwner, $link['link_order'], $link['link_position'] ) )
						{	// If file has been linked to the post:
							echo '<p class="text-success">'.sprintf( 'File %s has been linked to User %s.', '<code>'.$File->_adfp_full_path.'</code>', $User->get_identity_link() ).'</p>';
						}
						else
						{	// If file could not be linked to the post:
							echo '<p class="text-warning">'.sprintf( 'File %s could not be linked to User %s.', '<code>'.$File->_adfp_full_path.'</code>', $User->get_identity_link() ).'</p>';
						}
					}

					$files_count++;
				}
			}

			echo '<b>'.sprintf( '%d records', $files_count ).'</b></p>';
		}
	}

	/* Import categories */
	$category_default = 0;
	load_class( 'chapters/model/_chapter.class.php', 'Chapter' );

	// Get existing categories
	$SQL = new SQL();
	$SQL->SELECT( 'cat_urlname, cat_ID' );
	$SQL->FROM( 'T_categories' );
	$SQL->WHERE( 'cat_blog_ID = '.$DB->quote( $wp_blog_ID ) );
	$categories = $DB->get_assoc( $SQL->get() );

	if( isset( $xml_data['categories'] ) && count( $xml_data['categories'] ) > 0 )
	{
		echo '<p><b>'.'Importing the categories...'.' </b>';
		evo_flush();

		load_funcs( 'locales/_charset.funcs.php' );

		$categories_count = 0;
		foreach( $xml_data['categories'] as $cat )
		{
			echo '<p>'.sprintf( 'Importing category: %s', '"'.$cat['cat_name'].'"' ).'... ';

			if( ! empty( $categories[ (string) $cat['category_nicename'] ] ) )
			{
				echo '<span class="text-warning">'.sprintf( 'Skip because category #%d already exists with same slug %s.', intval( $categories[ (string) $cat['category_nicename'] ] ), '<code>'.$cat['category_nicename'].'</code>' ).'</span>';
			}
			else
			{
				$Chapter = new Chapter( NULL, $wp_blog_ID );
				$Chapter->set( 'name', $cat['cat_name'] );
				$Chapter->set( 'urlname', $cat['category_nicename'] );
				$Chapter->set( 'description', $cat['cat_description'] );
				$Chapter->set( 'order', ( $cat['cat_order'] === '' ? NULL : $cat['cat_order'] ), true );
				if( !empty( $cat['category_parent'] ) && isset( $categories[ (string) $cat['category_parent'] ] ) )
				{	// Set category parent ID
					$Chapter->set( 'parent_ID', $categories[ (string) $cat['category_parent'] ] );
				}
				$Chapter->dbinsert();

				// Save new category
				$categories[ $cat['category_nicename'] ] = $Chapter->ID;
				if( empty( $category_default ) )
				{	// Set first category as default
					$category_default = $Chapter->ID;
				}
				$categories_count++;
				echo '<span class="text-success">'.'OK'.'.</span>';
			}
		}

		echo '</p>';

		echo '<b>'.sprintf( '%d records', $categories_count ).'</b></p>';
	}

	if( empty( $category_default ) )
	{ // No categories in XML file, Try to use first category(from DB) as default
		foreach( $categories as $category_name => $category_ID )
		{
			$category_default = $category_ID;
			break;
		}
	}

	if( empty( $category_default ) )
	{ // If category is still not defined then we should create default, because blog must has at least one category
		$new_Chapter = new Chapter( NULL, $wp_blog_ID );
		$new_Chapter->set( 'name', 'Uncategorized' );
		$new_Chapter->set( 'urlname', $wp_Blog->get( 'urlname' ).'-main' );
		$new_Chapter->dbinsert();
		$category_default = $new_Chapter->ID;
	}

	/* Import tags */
	$tags = array();
	if( isset( $xml_data['tags'] ) && count( $xml_data['tags'] ) > 0 )
	{
		echo '<p><b>'.'Importing the tags...'.' </b>';
		evo_flush();

		// Get existing tags
		$SQL = new SQL();
		$SQL->SELECT( 'tag_name, tag_ID' );
		$SQL->FROM( 'T_items__tag' );
		$tags = $DB->get_assoc( $SQL->get() );

		$tags_count = 0;
		foreach( $xml_data['tags'] as $tag )
		{
			$tag_name = substr( html_entity_decode( $tag['tag_name'] ), 0, 50 );
			echo '<p>'.sprintf( 'Importing tag: %s', '"'.$tag_name.'"' ).'... ';
			if( ! empty( $tags[ $tag_name ] ) )
			{
				echo '<span class="text-warning">'.sprintf( 'Skip because tag #%d already exists with same name %s.', intval( $tags[ $tag_name ] ), '<code>'.$tag_name.'</code>' ).'</span>';
			}
			else
			{	// Insert new tag into DB if tag doesn't exist with current name
				$DB->query( 'INSERT INTO '.$tableprefix.'items__tag ( tag_name )
					VALUES ( '.$DB->quote( $tag_name ).' )' );
				$tag_ID = $DB->insert_id;
				// Save new tag
				$tags[ $tag_name ] = (string) $tag_ID;
				$tags_count++;
				echo '<span class="text-success">'.'OK'.'.</span>';
			}
		}
		echo '</p>';
		echo '<b>'.sprintf( '%d records', $tags_count ).'</b></p>';
	}


	/* Import posts */
	$posts = array();
	$comments = array();
	if( isset( $xml_data['posts'] ) && count( $xml_data['posts'] ) > 0 )
	{
		load_class( 'items/model/_item.class.php', 'Item' );

		$ChapterCache = & get_ChapterCache();
		// We MUST clear Chapters Cache here in order to avoid inserting new Items with wrong/previous/deleted Chapters from DB:
		$ChapterCache->clear();

		// Set status's links between WP and b2evo names
		$post_statuses = array(
			// WP statuses => Their analogs in b2evolution
			'publish'    => 'published',
			'pending'    => 'review',
			'draft'      => 'draft',
			'inherit'    => 'draft',
			'trash'      => 'deprecated',
			// These statuses don't exist in WP, but we handle them if they will appear once
			'community'  => 'community',
			'deprecated' => 'deprecated',
			'protected'  => 'protected',
			'private'    => 'private',
			'review'     => 'review',
			'redirected' => 'redirected'
			// All other unknown statuses will be converted to 'review'
		);

		echo '<p><b>'.'Importing the files from attachment posts...'.' </b>';
		evo_flush();

		$attached_post_files = array();
		$attachment_IDs = array();
		$attachments_count = 0;
		foreach( $xml_data['posts'] as $post )
		{	// Import ONLY attachment posts here, all other posts are imported below:
			if( $post['post_type'] != 'attachment' )
			{	// Skip not attachment post:
				continue;
			}

			echo '<p>'.sprintf( 'Importing attachment: %s', '#'.$post['post_id'].' - "'.$post['post_title'].'"' );

			if( ! empty( $post['post_parent'] ) )
			{	// Store what post the File is linked to:
				if( ! isset( $attached_post_files[ $post['post_parent'] ] ) )
				{
					$attached_post_files[ $post['post_parent'] ] = array();
				}
				$attached_post_files[ $post['post_parent'] ][] = $post['post_id'];
			}

			if( isset( $post['postmeta'] ) )
			{	// Link the files to the Item from meta data:
				$attch_imported_files = array();
				foreach( $post['postmeta'] as $postmeta )
				{
					if( ! isset( $postmeta['key'] ) || ! isset( $postmeta['value'] ) )
					{	// Skip wrong meta data:
						continue;
					}
					$attch_file_name = '';
					$file_params = array(
							'file_root_type' => 'collection',
							'file_root_ID'   => $wp_blog_ID,
							'file_title'     => $post['post_title'],
							'file_desc'      => empty( $post['post_content'] ) ? $post['post_excerpt'] : $post['post_content'],
						);

					if( $postmeta['key'] == '_wp_attached_file' )
					{	// Get file name from the string meta data:
						$attch_file_name = $postmeta['value'];
					}
					elseif( $postmeta['key'] == '_wp_attachment_metadata' )
					{	// Try to get file name from the serialized meta data:
						$postmeta_value = @unserialize( $postmeta['value'] );
						if( isset( $postmeta_value['file'] ) )
						{	// Set file name:
							$attch_file_name = $postmeta_value['file'];
						}
					}
					if( empty( $attch_file_name ) || in_array( $attch_file_name, $attch_imported_files ) )
					{	// Skip empty file name or if it has been already imported:
						continue;
					}

					// Set file path where we should store the importing file relating to the collection folder:
					$file_params['file_path'] = preg_replace( '#^.+[/\\\\]#', '', $attch_file_name );

					// Source of the importing file:
					$file_source_path = $attached_files_path.$attch_file_name;

					// Try to import file from source path:
					if( $File = & wpxml_create_File( $file_source_path, $file_params ) )
					{	// Store the created File in array because it will be linked to the Items below:
						$attachment_IDs[ $post['post_id'] ] = $File->ID;
						$files[ $File->ID ] = $File;

						if( $import_img )
						{	// Collect file name in array to link with post below:
							$file_name = basename( $file_source_path );
							if( isset( $all_wp_attachments[ $file_name ] ) )
							{	// Don't use this file if more than one use same name, e.g. from different folders:
								$all_wp_attachments[ $file_name ] = false;
							}
							else
							{	// This is a first detected file with current name:
								$all_wp_attachments[ $file_name ] = $File->ID;
							}
						}

						$attachments_count++;
						// Break here because such post can contains only one file:
						break;
					}

					$attch_imported_files[] = $attch_file_name;
				}
			}

			echo '</p>';
			evo_flush();
			$attachments_count++;
		}

		echo '<b>'.sprintf( '%d records', $attachments_count ).'</b></p>';

		echo '<p><b>'.'Importing the posts...'.' </b>';
		evo_flush();

		$posts_count = 0;
		foreach( $xml_data['posts'] as $post )
		{
			if( $post['post_type'] == 'revision' )
			{	// Ignore post with type "revision":
				echo '<p class="text-warning">'.sprintf( 'Ignore post "%s" because of post type is %s',
						'#'.$post['post_id'].' - '.$post['post_title'],
						'<code>'.$post['post_type'].'</code>' )
					.'</p>';
				continue;
			}
			elseif( $post['post_type'] == 'attachment' )
			{	// Skip attachment post because it shoul be imported above:
				continue;
			}
			elseif( $post['post_type'] == 'page' && ! isset( $categories['standalone-pages'] ) )
			{	// Try to create special category "Standalone Pages" for pages only it doesn't exist:
				$page_Chapter = new Chapter( NULL, $wp_blog_ID );
				$page_Chapter->set( 'name', 'Standalone Pages' );
				$page_Chapter->set( 'urlname', 'standalone-pages' );
				$page_Chapter->dbinsert();
				$categories['standalone-pages'] = $page_Chapter->ID;
				// Add new created chapter to cache to avoid error when this cache loaded all elements before:
				$ChapterCache->add( $page_Chapter );
			}

			echo '<p>'.sprintf( 'Importing post: %s', '#'.$post['post_id'].' - "'.$post['post_title'].'"... ' );

			$author_ID = isset( $authors[ (string) $post['post_author'] ] ) ? $authors[ (string) $post['post_author'] ] : 1;
			$last_edit_user_ID = isset( $authors[ (string) $post['post_lastedit_user'] ] ) ? $authors[ (string) $post['post_lastedit_user'] ] : $author_ID;

			$post_main_cat_ID = $category_default;
			$post_extra_cat_IDs = array();
			$post_tags = array();
			if( !empty( $post['terms'] ) )
			{ // Set categories and tags
				foreach( $post['terms'] as $term )
				{
					switch( $term['domain'] )
					{
						case 'category':
							if( isset( $categories[ (string) $term['slug'] ] ) )
							{
								if( $post_main_cat_ID == $category_default )
								{ // Set main category
									$post_main_cat_ID = $categories[ (string) $term['slug'] ];
								}
								// Set extra categories
								$post_extra_cat_IDs[] = $categories[ (string) $term['slug'] ];
							}
							break;

						case 'post_tag':
							$tag_name = substr( html_entity_decode( $term['slug'] ), 0, 50 );
							if( isset( $tags[ $tag_name ] ) )
							{ // Set tag
								$post_tags[] = $tag_name;
							}
							break;
					}
				}
			}

			if( $post['post_type'] == 'page' )
			{	// Set static category "Standalone Pages" for pages because they have no categories in wordpress DB:
				$post_main_cat_ID = $categories['standalone-pages'];
				$post_extra_cat_IDs[] = $categories['standalone-pages'];
			}

			// Set Item Type ID:
			if( ! empty( $post['itemtype'] ) && isset( $selected_item_type_names[ $post['itemtype'] ] ) )
			{	// Try to use Item Type by name:
				$post_type_ID = $selected_item_type_names[ $post['itemtype'] ];
				if( $post_type_ID == 0 )
				{	// Skip Item because this was selected on the confirm form:
					echo '<p class="text-warning"><span class="label label-warning">'.'WARNING'.'</span> '
						.sprintf( 'Skip Item because Item Type %s is not selected for import.',
							'<code>&lt;evo:itemtype&gt;</code> = <code>'.$post['itemtype'].'</code>' ).'</p>';
					continue;
				}
			}
			elseif( ! empty( $post['post_type'] ) && isset( $selected_item_type_usages[ $post['post_type'] ] ) )
			{	// Try to use Item Type by usage:
				$post_type_ID = $selected_item_type_usages[ $post['post_type'] ];
				if( $post_type_ID == 0 )
				{	// Skip Item because this was selected on the confirm form:
					echo '<p class="text-warning"><span class="label label-warning">'.'WARNING'.'</span> '
						.sprintf( 'Skip Item because Item Type %s is not selected for import.',
							'<code>&lt;wp:post_type&gt;</code> = <code>'.$post['post_type'].'</code>' ).'</p>';
					continue;
				}
			}
			else
			{	// Try to use Item Type without provided value sin XML:
				$post_type_ID = $selected_item_type_none;
				if( $post_type_ID == 0 )
				{	// Skip Item because this was selected on the confirm form:
					echo '<p class="text-warning"><span class="label label-warning">'.'WARNING'.'</span> '
						.'Skip Item because you didn\'t select to import without provided item type.'.'</p>';
					continue;
				}
			}

			$ItemTypeCache = & get_ItemTypeCache();
			if( ! ( $ItemType = & $ItemTypeCache->get_by_ID( $post_type_ID, false, false ) ) )
			{	// Skip not found Item Type:
				echo '<p class="text-danger"><span class="label label-danger">'.'ERROR'.'</span> '.sprintf( 'Skip Item because Item Type %s is not found.', '<code>'.( isset( $post['itemtype'] ) ? $post['itemtype'] : $post['post_type'] ).'</code>' ).'</p>';
				continue;
			}

			$Item = new Item();

			if( ! empty( $post['custom_fields'] ) )
			{	// Import custom fields:
				$item_type_custom_fields = $ItemType->get_custom_fields();
				//pre_dump( $post['custom_fields'], $item_type_custom_fields );
				foreach( $post['custom_fields'] as $custom_field_name => $custom_field )
				{
					if( ! isset( $item_type_custom_fields[ $custom_field_name ] ) )
					{	// Skip unknown custom field:
						echo '<p class="text-danger"><span class="label label-danger">'.'ERROR'.'</span> '.sprintf( 'Skip custom field %s because Item Type %s has no it.', '<code>'.$custom_field_name.'</code>', '#'.$ItemType->ID.' "'.$ItemType->get( 'name' ).'"' ).'</p>';
						continue;
					}
					if( $item_type_custom_fields[ $custom_field_name ]['type'] != $custom_field['type'] )
					{	// Skip wrong custom field type:
						echo '<p class="text-danger"><span class="label label-danger">'.'ERROR'.'</span> '.sprintf( 'Cannot import custom field %s because it has type %s and we expect type %s', '<code>'.$custom_field_name.'</code>', '<code>'.$custom_field['type'].'</code>', '<code>'.$item_type_custom_fields[ $custom_field_name ]['type'].'</code>' ).'</p>';
						continue;
					}
					$Item->set_custom_field( $custom_field_name, $custom_field['value'] );
				}
			}

			// Get regional IDs by their names
			$item_regions = wp_get_regional_data( $post['post_country'], $post['post_region'], $post['post_subregion'], $post['post_city'] );

			$post_content = $post['post_content'];

			// Use title by default to generate new slug:
			$post_urltitle = $post['post_title'];
			if( ! empty( $post['post_urltitle'] ) )
			{	// Use top priority urltitle if it is provided:
				$post_urltitle = $post['post_urltitle'];
			}
			elseif( ! empty( $post['post_link'] ) && preg_match( '#/([^/]+)/?$#', $post['post_link'], $post_link_match ) )
			{	// Try to extract canonical slug from post URL:
				$post_urltitle = $post_link_match[1];
			}

			$Item->set( 'main_cat_ID', $post_main_cat_ID );
			$Item->set( 'creator_user_ID', $author_ID );
			$Item->set( 'lastedit_user_ID', $last_edit_user_ID );
			$Item->set( 'title', $post['post_title'] );
			$Item->set( 'content', $post_content );
			$Item->set( 'datestart', $post['post_date'] );
			$Item->set( 'datecreated', !empty( $post['post_datecreated'] ) ? $post['post_datecreated'] : $post['post_date'] );
			$Item->set( 'datemodified', !empty( $post['post_datemodified'] ) ? $post['post_datemodified'] : $post['post_date'] );
			$Item->set( 'urltitle', $post_urltitle );
			$Item->set( 'url', $post['post_url'] );
			$Item->set( 'status', isset( $post_statuses[ (string) $post['status'] ] ) ? $post_statuses[ (string) $post['status'] ] : 'review' );
			// If 'comment_status' has the unappropriate value set it to 'open'
			$Item->set( 'comment_status', ( in_array( $post['comment_status'], array( 'open', 'closed', 'disabled' ) ) ? $post['comment_status'] : 'open' ) );
			$Item->set( 'ityp_ID', $post_type_ID );
			if( empty( $post['post_excerpt'] ) )
			{	// If excerpt is not provided:
				if( ! empty( $post_content ) )
				{	// Generate excerpt from content:
					$Item->set_param( 'excerpt', 'string', excerpt( $post_content ), true );
					$Item->set( 'excerpt_autogenerated', 1 );
				}
			}
			else
			{	// Set excerpt from given value:
				$Item->set_param( 'excerpt', 'string', $post['post_excerpt'], true );
			}
			$Item->set( 'extra_cat_IDs', $post_extra_cat_IDs );
			$Item->set( 'dateset', $post['post_date_mode'] == 'set' ? 1 : 0 );
			if( isset( $authors[ (string) $post['post_assigned_user'] ] ) )
			{
				$Item->set( 'assigned_user', $authors[ (string) $post['post_assigned_user'] ] );
			}
			$Item->set( 'datedeadline', $post['post_datedeadline'] );
			$Item->set( 'locale', $post['post_locale'] );
			$Item->set( 'excerpt_autogenerated', $post['post_excerpt_autogenerated'] );
			$Item->set( 'titletag', $post['post_titletag'] );
			$Item->set( 'notifications_status', empty( $post['post_notifications_status'] ) ? 'noreq' : $post['post_notifications_status'] );
			$Item->set( 'renderers', array( $post['post_renderers'] ) );
			$Item->set( 'priority', $post['post_priority'] );
			$Item->set( 'featured', $post['post_featured'] );
			$Item->set( 'order', $post['post_order'] );
			if( !empty( $item_regions['country'] ) )
			{	// Country
				$Item->set( 'ctry_ID', $item_regions['country'] );
				if( !empty( $item_regions['region'] ) )
				{	// Region
					$Item->set( 'rgn_ID', $item_regions['region'] );
					if( !empty( $item_regions['subregion'] ) )
					{	// Subregion
						$Item->set( 'subrg_ID', $item_regions['subregion'] );
					}
					if( !empty( $item_regions['city'] ) )
					{	// City
						$Item->set( 'city_ID', $item_regions['city'] );
					}
				}
			}

			if( count( $post_tags ) > 0 )
			{
				$Item->tags = $post_tags;
			}

			$Item->dbinsert();
			$posts[ $post['post_id'] ] = $Item->ID;

			$LinkOwner = new LinkItem( $Item );
			$updated_post_content = $post_content;
			$link_order = 1;

			if( ! empty( $files ) && ! empty( $post['links'] ) )
			{	// Link the files to the Item if it has them:
				foreach( $post['links'] as $link )
				{
					$file_is_linked = false;
					if( isset( $files[ $link['link_file_ID'] ] ) )
					{	// Link a File to Item:
						$File = $files[ $link['link_file_ID'] ];
						if( $File->link_to_Object( $LinkOwner, $link['link_order'], $link['link_position'] ) )
						{	// If file has been linked to the post
							echo '<p class="text-success">'.sprintf( 'File %s has been linked to this post.', '<code>'.$File->_adfp_full_path.'</code>' ).'</p>';
							$file_is_linked = true;
							// Update link order to the latest for two other ways([caption] and <img />) below:
							$link_order = $link['link_order'];
						}
					}
					if( ! $file_is_linked )
					{	// If file could not be linked to the post:
						echo '<p class="text-warning">'.sprintf( 'Link %s could not be attached to this post because file %s is not found.', '#'.$link['link_ID'], '#'.$link['link_file_ID'] ).'</p>';
					}
				}
			}

			$linked_post_files = array();
			if( isset( $post['postmeta'] ) )
			{	// Extract additional data:
				foreach( $post['postmeta'] as $postmeta )
				{
					switch( $postmeta['key'] )
					{
						case '_thumbnail_id':
							// Try to link the File as cover:
							$linked_post_files[] = $postmeta['value'];
							$file_is_linked = false;
							if( isset( $attachment_IDs[ $postmeta['value'] ] ) && isset( $files[ $attachment_IDs[ $postmeta['value'] ] ] ) )
							{
								$File = $files[ $attachment_IDs[ $postmeta['value'] ] ];
								if( $File->link_to_Object( $LinkOwner, $link_order, 'cover' ) )
								{	// If file has been linked to the post:
									echo '<p class="text-success">'.sprintf( 'File %s has been linked to this post as cover.', '<code>'.$File->_adfp_full_path.'</code>' ).'</p>';
									$file_is_linked = true;
									$link_order++;
								}
							}
							if( ! $file_is_linked )
							{	// If file could not be linked to the post:
								echo '<p class="text-warning">'.sprintf( 'Cover file %s could not be attached to this post because it is not found in the source attachments folder.', '#'.$postmeta['value'] ).'</p>';
							}
							break;
					}
				}
			}

			// Try to extract files from content tag [caption ...]:
			if( preg_match_all( '#\[caption[^\]]+id="attachment_(\d+)"[^\]]+\].+?\[/caption\]#i', $updated_post_content, $caption_matches ) )
			{	// If [caption ...] tag is detected
				foreach( $caption_matches[1] as $caption_post_ID )
				{
					$linked_post_files[] = $caption_post_ID;
					$file_is_linked = false;
					if( isset( $attachment_IDs[ $caption_post_ID ] ) && isset( $files[ $attachment_IDs[ $caption_post_ID ] ] ) )
					{
						$File = $files[ $attachment_IDs[ $caption_post_ID ] ];
						if( $link_ID = $File->link_to_Object( $LinkOwner, $link_order, 'inline' ) )
						{	// If file has been linked to the post
							echo '<p class="text-success">'.sprintf( 'File %s has been linked to this post.', '<code>'.$File->_adfp_full_path.'</code>' ).'</p>';
							// Replace this caption tag from content with b2evolution format:
							$updated_post_content = preg_replace( '#\[caption[^\]]+id="attachment_'.$caption_post_ID.'"[^\]]+\].+?\[/caption\]#i', ( $File->is_image() ? '[image:'.$link_ID.']' : '[file:'.$link_ID.']' ), $updated_post_content );
							$file_is_linked = true;
							$link_order++;
						}
					}
					if( ! $file_is_linked )
					{	// If file could not be linked to the post:
						echo '<p class="text-warning">'.sprintf( 'Caption file %s could not be attached to this post because it is not found in the source attachments folder.', '#'.$caption_post_ID ).'</p>';
					}
				}
			}

			// Try to extract files from html tag <img />:
			if( $import_img && count( $all_wp_attachments ) )
			{	// Only if it is requested and at least one attachment has been detected above:
				if( preg_match_all( '#<img[^>]+src="([^"]+)"[^>]+>#i', $updated_post_content, $img_matches ) )
				{	// If <img /> tag is detected
					foreach( $img_matches[1] as $img_url )
					{
						$file_is_linked = false;
						$img_file_name = basename( $img_url );
						if( isset( $all_wp_attachments[ $img_file_name ], $files[ $all_wp_attachments[ $img_file_name ] ] ) )
						{
							$File = $files[ $all_wp_attachments[ $img_file_name ] ];
							if( $linked_post_ID = array_search( $File->ID, $attachment_IDs ) )
							{
								$linked_post_files[] = $linked_post_ID;
							}
							if( $link_ID = $File->link_to_Object( $LinkOwner, $link_order, 'inline' ) )
							{	// If file has been linked to the post
								echo '<p class="text-success">'.sprintf( 'File %s has been linked to this post.', '<code>'.$File->_adfp_full_path.'</code>' ).'</p>';
								// Replace this img tag from content with b2evolution format:
								$updated_post_content = preg_replace( '#<img[^>]+src="[^"]+'.preg_quote( $img_file_name ).'"[^>]+>#i', '[image:'.$link_ID.']', $updated_post_content );
								$file_is_linked = true;
								$link_order++;
							}
						}
						if( ! $file_is_linked )
						{	// If file could not be linked to the post:
							echo '<p class="text-warning">'.sprintf( 'File of img src=%s could not be attached to this post because the name %s does not match any %s or %s.',
								'<code>'.$img_url.'</code>',
								'<code>'.$img_file_name.'</code>',
								'<code>&lt;evo:file&gt;</code>',
								'<code>&lt;item&gt;&lt;wp:post_type&gt;attachment&lt;/wp:post_type&gt;...</code>' ).'</p>';
						}
					}
				}
			}

			if( isset( $attached_post_files[ $post['post_id'] ] ) )
			{	// Link all found attached files for the Item which were not linked yer above as cover or inline image tags:
				foreach( $attached_post_files[ $post['post_id'] ] as $attachment_post_ID )
				{
					if( in_array( $attachment_post_ID, $linked_post_files ) )
					{	// Skip already linked File:
						continue;
					}
					$file_is_linked = false;
					if( isset( $attachment_IDs[ $attachment_post_ID ] ) && isset( $files[ $attachment_IDs[ $attachment_post_ID ] ] ) )
					{
						$File = $files[ $attachment_IDs[ $attachment_post_ID ] ];
						if( $File->link_to_Object( $LinkOwner, $link_order, 'aftermore' ) )
						{	// If file has been linked to the post:
							echo '<p class="text-success">'.sprintf( 'File %s has been linked to this post.', '<code>'.$File->_adfp_full_path.'</code>' ).'</p>';
							$file_is_linked = true;
							$link_order++;
						}
					}
					if( ! $file_is_linked )
					{	// If file could not be linked to the post:
						echo '<p class="text-warning">'.sprintf( 'File %s could not be attached to this post because it is not found in the source attachments folder.', '#'.$attachment_post_ID ).'</p>';
					}
				}
			}

			if( $updated_post_content != $post_content )
			{	// Update new content:
				$Item->set( 'content', $updated_post_content );
				$Item->dbupdate();
			}

			if( !empty( $post['comments'] ) )
			{ // Set comments
				$comments[ $Item->ID ] = $post['comments'];
			}

			echo '<span class="text-success">'.'OK'.'.</span>';
			echo '</p>';
			evo_flush();
			$posts_count++;
		}

		foreach( $xml_data['posts'] as $post )
		{	// Set post parents
			if( !empty( $post['post_parent'] ) && isset( $posts[ (string) $post['post_parent'] ], $posts[ (string) $post['post_id'] ] ) )
			{
				$DB->query( 'UPDATE '.$tableprefix.'items__item
						  SET post_parent_ID = '.$DB->quote( $posts[ (string) $post['post_parent'] ] ).'
						WHERE post_ID = '.$DB->quote( $posts[ (string) $post['post_id'] ] ) );
			}
		}

		echo '<b>'.sprintf( '%d records', $posts_count ).'</b></p>';
	}


	/* Import comments */
	if( !empty( $comments ) )
	{
		echo '<p><b>'.'Importing the comments...'.' </b>';
		evo_flush();

		$comments_count = 0;
		$comments_IDs = array();
		foreach( $comments as $post_ID => $comments )
		{
			$post_comments_count = 0;
			echo '<p>'.sprintf( 'Importing comments of the post #%d', intval( $post_ID ) ).'... ';
			if( empty( $comments ) )
			{	// Skip if no comments
				echo '<span class="text-warning">'.'Skip because the post has no comments.'.'</span>';
				continue;
			}

			foreach( $comments as $comment )
			{
				$comment_author_user_ID = 0;
				if( !empty( $comment['comment_user_id'] ) && isset( $authors_IDs[ (string) $comment['comment_user_id'] ] ) )
				{	// Author ID
					$comment_author_user_ID = $authors_IDs[ (string) $comment['comment_user_id'] ];
				}

				$comment_parent_ID = 0;
				if( !empty( $comment['comment_parent'] ) && isset( $comments_IDs[ (string) $comment['comment_parent'] ] ) )
				{	// Parent comment ID
					$comment_parent_ID = $comments_IDs[ (string) $comment['comment_parent'] ];
				}

				unset( $comment_IP_country );
				if( !empty( $comment['comment_IP_country'] ) )
				{	// Get country ID by code
					$CountryCache = & get_CountryCache();
					if( $Country = & $CountryCache->get_by_name( $comment['comment_IP_country'], false ) )
					{
						$comment_IP_country = $Country->ID;
					}
				}

				$Comment = new Comment();
				$Comment->set( 'item_ID', $post_ID );
				if( !empty( $comment_parent_ID ) )
				{
					$Comment->set( 'in_reply_to_cmt_ID', $comment_parent_ID );
				}
				$Comment->set( 'date', $comment['comment_date'] );
				if( !empty( $comment_author_user_ID ) )
				{
					$Comment->set( 'author_user_ID', $comment_author_user_ID );
				}
				$Comment->set( 'author', utf8_substr( $comment['comment_author'], 0, 100 ) );
				$Comment->set( 'author_IP', $comment['comment_author_IP'] );
				$Comment->set( 'author_email', $comment['comment_author_email'] );
				$Comment->set( 'author_url', $comment['comment_author_url'] );
				$Comment->set( 'content', $comment['comment_content'] );
				if( empty( $comment['comment_status'] ) )
				{	// If comment status is empty (the export of wordpress doesn't provide this field)
					$Comment->set( 'status', $comment['comment_approved'] == '1' ? 'published' : 'draft' );
				}
				else
				{	// Set status when we have predefined value
					$Comment->set( 'status', $comment['comment_status'] );
				}
				if( !empty( $comment_IP_country ) )
				{	// Country
					$Comment->set( 'IP_ctry_ID', $comment_IP_country );
				}
				$Comment->set( 'rating', $comment['comment_rating'] );
				$Comment->set( 'featured', $comment['comment_featured'] );
				$Comment->set( 'author_url_nofollow', $comment['comment_author_url_nofollow'] );
				$Comment->set( 'author_url_ugc', $comment['comment_author_url_ugc'] );
				$Comment->set( 'author_url_sponsored', $comment['comment_author_url_sponsored'] );
				$Comment->set( 'helpful_addvotes', $comment['comment_helpful_addvotes'] );
				$Comment->set( 'helpful_countvotes', $comment['comment_helpful_countvotes'] );
				$Comment->set( 'spam_addvotes', $comment['comment_spam_addvotes'] );
				$Comment->set( 'spam_countvotes', $comment['comment_spam_countvotes'] );
				$Comment->set( 'karma', $comment['comment_karma'] );
				$Comment->set( 'spam_karma', $comment['comment_spam_karma'] );
				$Comment->set( 'allow_msgform', $comment['comment_allow_msgform'] );
				$Comment->set( 'notif_status', empty( $comment['comment_notif_status'] ) ? 'noreq' : $comment['comment_notif_status'] );
				$Comment->dbinsert();

				$comments_IDs[ $comment['comment_id'] ] = $Comment->ID;
				$comments_count++;
				$post_comments_count++;
				
				echo '.';
			}

			echo ' <span class="text-success">'.sprintf( '%d comments', $post_comments_count ).'.</span>';

			echo '</p>';
		}

		echo '<b>'.sprintf( '%d records', $comments_count ).'</b></p>';
	}

	echo '<p class="text-success">'.'Import complete.'.'</p>';

	$DB->commit();
}


/**
 * Parse WordPress XML file into array
 *
 * @param string File path
 * @return array XML data:
 *          authors
 *          posts
 *          categories
 *          tags
 *          terms
 *          base_url
 *          wxr_version
 */
function wpxml_parser( $file )
{
	$authors = array();
	$posts = array();
	$categories = array();
	$tags = array();
	$terms = array();
	$files = array();
	$memory = array();

	// Register filter to avoid wrong chars in XML content:
	stream_filter_register( 'xmlutf8', 'ValidUTF8XMLFilter' );

	// Start to get amount of memory for parsing:
	$memory_usage = memory_get_usage();

	// Load XML content from file with xmlutf8 filter:
	$xml = simplexml_load_file( 'php://filter/read=xmlutf8/resource='.$file );

	// Store here what memory was used for XML parsing:
	$memory['parsing'] = memory_get_usage() - $memory_usage;

	// Get WXR version
	$wxr_version = $xml->xpath( '/rss/channel/wp:wxr_version' );
	$wxr_version = isset( $wxr_version[0] ) ? (string) trim( $wxr_version[0] ) : '';

	$base_url = $xml->xpath( '/rss/channel/wp:base_site_url' );
	$base_url = isset( $base_url[0] ) ? (string) trim( $base_url[0] ) : '';

	// Check language
	global $evo_charset, $xml_import_convert_to_latin;
	$language = $xml->xpath( '/rss/channel/language' );
	$language = isset( $language[0] ) ? (string) trim( $language[0] ) : '';
	if( $evo_charset != 'utf-8' && ( strpos( $language, 'utf8' ) !== false ) )
	{ // We should convert the text values from utf8 to latin1
		$xml_import_convert_to_latin = true;
	}
	else
	{ // Don't convert, it is correct encoding
		$xml_import_convert_to_latin = false;
	}

	$namespaces = $xml->getDocNamespaces();
	if( !isset( $namespaces['wp'] ) )
	{
		$namespaces['wp'] = 'http://wordpress.org/export/1.1/';
	}
	if( !isset( $namespaces['evo'] ) )
	{
		$namespaces['evo'] = 'http://b2evolution.net/export/2.0/';
	}
	if( !isset( $namespaces['excerpt'] ) )
	{
		$namespaces['excerpt'] = 'http://wordpress.org/export/1.1/excerpt/';
	}

	// Start to get amount of memory for temporary arrays:
	$memory_usage = memory_get_usage();

	// Get authors:
	$authors_data = $xml->xpath( '/rss/channel/wp:author' );
	if( is_array( $authors_data ) )
	{
		foreach( $authors_data as $author_arr )
		{
			$a = $author_arr->children( $namespaces['wp'] );
			$ae = $author_arr->children( $namespaces['evo'] );
			$login = (string) $a->author_login;
			$author = array(
				'author_id'                   => (int) $a->author_id,
				'author_login'                => $login,
				'author_email'                => (string) $a->author_email,
				'author_display_name'         => wpxml_convert_value( $a->author_display_name ),
				'author_first_name'           => wpxml_convert_value( $a->author_first_name ),
				'author_last_name'            => wpxml_convert_value( $a->author_last_name ),
				'author_pass'                 => (string) $ae->author_pass,
				'author_salt'                 => isset( $ae->author_salt ) ? (string) $ae->author_salt : '',
				'author_pass_driver'          => isset( $ae->author_pass_driver ) ? (string) $ae->author_pass_driver : 'evo$md5',
				'author_group'                => (string) $ae->author_group,
				'author_status'               => (string) $ae->author_status,
				'author_nickname'             => wpxml_convert_value( $ae->author_nickname ),
				'author_url'                  => (string) $ae->author_url,
				'author_level'                => (int) $ae->author_level,
				'author_locale'               => (string) $ae->author_locale,
				'author_gender'               => (string) $ae->author_gender,
				'author_age_min'              => (int) $ae->author_age_min,
				'author_age_max'              => (int) $ae->author_age_max,
				'author_created_from_country' => (string) $ae->author_created_from_country,
				'author_country'              => (string) $ae->author_country,
				'author_region'               => (string) $ae->author_region,
				'author_subregion'            => (string) $ae->author_subregion,
				'author_city'                 => (string) $ae->author_city,
				'author_source'               => (string) $ae->author_source,
				'author_created_ts'           => (string) $ae->author_created_ts,
				'author_lastseen_ts'          => (string) $ae->author_lastseen_ts,
				'author_created_fromIPv4'     => (string) $ae->author_created_fromIPv4,
				'author_profileupdate_date'   => (string) $ae->author_profileupdate_date,
				'author_avatar_file_ID'       => (int) $ae->author_avatar_file_ID,
			);

			foreach( $ae->link as $link )
			{	// Get the links:
				$author['links'][] = array(
					'link_ID'               => (int) $link->link_ID,
					'link_datecreated'      => (string) $link->link_datecreated,
					'link_datemodified'     => (string) $link->link_datemodified,
					'link_creator_user_ID'  => (int) $link->link_creator_user_ID,
					'link_lastedit_user_ID' => (int) $link->link_lastedit_user_ID,
					'link_itm_ID'           => (int) $link->link_itm_ID,
					'link_cmt_ID'           => (int) $link->link_cmt_ID,
					'link_usr_ID'           => (int) $link->link_usr_ID,
					'link_file_ID'          => (int) $link->link_file_ID,
					'link_position'         => (string) $link->link_position,
					'link_order'            => (int) $link->link_order,
				);
			}

			$authors[ $login ] = $author;
		}
	}

	// Get files:
	$files_data = $xml->xpath( $namespaces['evo'] == 'http://b2evolution.net/export/2.0/'
		? '/rss/channel/evo:file' // ver 2.0
		: '/rss/channel/file' ); // ver 1.0
	if( is_array( $files_data ) )
	{
		foreach( $files_data as $file_arr )
		{
			$t = $file_arr->children( $namespaces['evo'] );
			$files[] = array(
				'file_ID'        => (int) $t->file_ID,
				'file_root_type' => (string) $t->file_root_type,
				'file_root_ID'   => (int) $t->file_root_ID,
				'file_path'      => (string) $t->file_path,
				'file_title'     => wpxml_convert_value( $t->file_title ),
				'file_alt'       => wpxml_convert_value( $t->file_alt ),
				'file_desc'      => wpxml_convert_value( $t->file_desc ),
				'zip_path'       => (string) $t->zip_path,
			);
		}
	}

	// Get categories:
	$categories_data = $xml->xpath( '/rss/channel/wp:category' );
	if( is_array( $categories_data ) )
	{
		foreach( $categories_data as $term_arr )
		{
			$t = $term_arr->children( $namespaces['wp'] );
			$categories[] = array(
				'term_id'              => (int) $t->term_id,
				'category_nicename'    => wpxml_convert_value( $t->category_nicename ),
				'category_parent'      => (string) $t->category_parent,
				'cat_name'             => wpxml_convert_value( $t->cat_name ),
				'cat_description'      => wpxml_convert_value( $t->cat_description ),
				'cat_order'            => wpxml_convert_value( $t->cat_order ),
			);
		}
	}

	// Get tags:
	$tags_data = $xml->xpath( '/rss/channel/wp:tag' );
	if( is_array( $tags_data ) )
	{
		foreach( $tags_data as $term_arr )
		{
			$t = $term_arr->children( $namespaces['wp'] );
			$tags[] = array(
				'term_id'         => (int) $t->term_id,
				'tag_slug'        => (string) $t->tag_slug,
				'tag_name'        => wpxml_convert_value( $t->tag_name ),
				'tag_description' => wpxml_convert_value( $t->tag_description )
			);
		}
	}

	// Get terms:
	$terms_data = $xml->xpath( '/rss/channel/wp:term' );
	if( is_array( $terms_data ) )
	{
		foreach( $terms_data as $term_arr )
		{
			$t = $term_arr->children( $namespaces['wp'] );
			$terms[] = array(
				'term_id'          => (int) $t->term_id,
				'term_taxonomy'    => (string) $t->term_taxonomy,
				'slug'             => (string) $t->term_slug,
				'term_parent'      => (string) $t->term_parent,
				'term_name'        => wpxml_convert_value( $t->term_name ),
				'term_description' => wpxml_convert_value( $t->term_description )
			);
		}
	}

	// Get posts
	foreach( $xml->channel->item as $item )
	{
		$post = array(
			'post_title' => wpxml_convert_value( $item->title ),
			'post_link'  => ( isset( $item->link ) ? wpxml_convert_value( $item->link ) : '' ),
			'guid'       => (string) $item->guid,
		);

		$dc = $item->children( 'http://purl.org/dc/elements/1.1/' );
		$post['post_author'] = (string) $dc->creator;

		$content = $item->children( 'http://purl.org/rss/1.0/modules/content/' );
		$excerpt = $item->children( $namespaces['excerpt'] );
		$post['post_content'] = wpxml_convert_value( $content->encoded );
		$post['post_excerpt'] = wpxml_convert_value( $excerpt->encoded );

		$wp = $item->children( $namespaces['wp'] );
		$evo = $item->children( $namespaces['evo'] );

		$post['post_id']        = (int) $wp->post_id;
		$post['post_date']      = (string) $wp->post_date;
		$post['post_date_gmt']  = (string) $wp->post_date_gmt;
		$post['comment_status'] = (string) $wp->comment_status;
		$post['ping_status']    = (string) $wp->ping_status;
		$post['post_name']      = (string) $wp->post_name;
		$post['status']         = (string) $wp->status;
		$post['post_parent']    = (int) $wp->post_parent;
		$post['menu_order']     = (int) $wp->menu_order;
		$post['post_type']      = (string) $wp->post_type;
		$post['post_password']  = (string) $wp->post_password;
		$post['is_sticky']      = (int) $wp->is_sticky;
		$post['itemtype']       = (string) $evo->itemtype;
		$post['post_date_mode']     = (string) $evo->post_date_mode;
		$post['post_lastedit_user'] = (string) $evo->post_lastedit_user;
		$post['post_assigned_user'] = (string) $evo->post_assigned_user;
		$post['post_datedeadline']  = (string) $evo->post_datedeadline;
		$post['post_datecreated']   = (string) $evo->post_datecreated;
		$post['post_datemodified']  = (string) $evo->post_datemodified;
		$post['post_locale']        = (string) $evo->post_locale;
		$post['post_excerpt_autogenerated'] = (int) $evo->post_excerpt_autogenerated;
		$post['post_urltitle']      = (string) $evo->post_urltitle;
		$post['post_titletag']      = (string) $evo->post_titletag;
		$post['post_url']           = (string) $evo->post_url;
		$post['post_notifications_status'] = (string) $evo->post_notifications_status;
		$post['post_renderers']     = (string) $evo->post_renderers;
		$post['post_priority']      = (int) $evo->post_priority;
		$post['post_featured']      = (int) $evo->post_featured;
		$post['post_order']         = (int) $evo->post_order;
		$post['post_country']       = (string) $evo->post_country;
		$post['post_region']        = (string) $evo->post_region;
		$post['post_subregion']     = (string) $evo->post_subregion;
		$post['post_city']          = (string) $evo->post_city;

		if( isset( $wp->attachment_url ) )
		{
			$post['attachment_url'] = (string) $wp->attachment_url;
		}

		foreach ( $item->category as $c )
		{
			$att = $c->attributes();
			if( isset( $att['nicename'] ) )
			{
				$post['terms'][] = array(
					'name'   => (string) $c,
					'slug'   => wpxml_convert_value( $att['nicename'] ),
					'domain' => (string) $att['domain']
				);
			}
		}

		if( isset( $evo->custom_field ) )
		{	// Parse values of custom fields of the Item:
			foreach( $evo->custom_field as $custom_field )
			{
				$custom_field_attrs = $custom_field->attributes();
				$post['custom_fields'][ wpxml_convert_value( $custom_field_attrs->name ) ] = array(
						'type'  => wpxml_convert_value( $custom_field_attrs->type ),
						'value' => wpxml_convert_value( $custom_field ),
					);
			}
		}

		foreach( $wp->postmeta as $meta )
		{
			$post['postmeta'][] = array(
				'key'   => (string) $meta->meta_key,
				'value' => wpxml_convert_value( $meta->meta_value )
			);
		}

		foreach( $wp->comment as $comment )
		{
			$evo_comment = $comment->children( $namespaces['evo'] );

			$meta = array();
			if( isset( $comment->commentmeta ) )
			{
				foreach( $comment->commentmeta as $m )
				{
					$meta[] = array(
						'key'   => (string) $m->meta_key,
						'value' => wpxml_convert_value( $m->meta_value )
					);
				}
			}

			$post['comments'][] = array(
				'comment_id'           => (int) $comment->comment_id,
				'comment_author'       => wpxml_convert_value( $comment->comment_author ),
				'comment_author_email' => (string) $comment->comment_author_email,
				'comment_author_IP'    => (string) $comment->comment_author_IP,
				'comment_author_url'   => (string) $comment->comment_author_url,
				'comment_date'         => (string) $comment->comment_date,
				'comment_date_gmt'     => (string) $comment->comment_date_gmt,
				'comment_content'      => wpxml_convert_value( $comment->comment_content ),
				'comment_approved'     => (string) $comment->comment_approved,
				'comment_type'         => (string) $comment->comment_type,
				'comment_parent'       => (string) $comment->comment_parent,
				'comment_user_id'      => (int) $comment->comment_user_id,
				'comment_status'             => (string) $evo_comment->comment_status,
				'comment_IP_country'         => (string) $evo_comment->comment_IP_country,
				'comment_rating'             => (int) $evo_comment->comment_rating,
				'comment_featured'           => (int) $evo_comment->comment_featured,
				'comment_author_url_nofollow'  => isset( $evo_comment->comment_author_url_nofollow ) ? (int) $evo_comment->comment_author_url_nofollow : (int) $evo_comment->comment_nofollow,
				'comment_author_url_ugc'       => isset( $evo_comment->comment_author_url_ugc ) ? (int) $evo_comment->comment_author_url_ugc : 1,
				'comment_author_url_sponsored' => isset( $evo_comment->comment_author_url_sponsored ) ? (int) $evo_comment->comment_author_url_sponsored : 0,
				'comment_helpful_addvotes'   => (int) $evo_comment->comment_helpful_addvotes,
				'comment_helpful_countvotes' => (int) $evo_comment->comment_helpful_countvotes,
				'comment_spam_addvotes'      => (int) $evo_comment->comment_spam_addvotes,
				'comment_spam_countvotes'    => (int) $evo_comment->comment_spam_countvotes,
				'comment_karma'              => (int) $evo_comment->comment_comment_karma,
				'comment_spam_karma'         => (int) $evo_comment->comment_spam_karma,
				'comment_allow_msgform'      => (int) $evo_comment->comment_allow_msgform,
				'comment_notif_status'       => (string) $evo_comment->comment_notif_status,
				'commentmeta'                => $meta,
			);
		}

		foreach( $evo->link as $link )
		{ // Get the links
			$post['links'][] = array(
				'link_ID'               => (int) $link->link_ID,
				'link_datecreated'      => (string) $link->link_datecreated,
				'link_datemodified'     => (string) $link->link_datemodified,
				'link_creator_user_ID'  => (int) $link->link_creator_user_ID,
				'link_lastedit_user_ID' => (int) $link->link_lastedit_user_ID,
				'link_itm_ID'           => (int) $link->link_itm_ID,
				'link_cmt_ID'           => (int) $link->link_cmt_ID,
				'link_usr_ID'           => (int) $link->link_usr_ID,
				'link_file_ID'          => (int) $link->link_file_ID,
				'link_position'         => (string) $link->link_position,
				'link_order'            => (int) $link->link_order,
			);
		}

		$posts[] = $post;
	}

	// Store here what memory was used for temporary arrays:
	$memory['arrays'] = memory_get_usage() - $memory_usage;

	return array(
		'authors'    => $authors,
		'files'      => $files,
		'posts'      => $posts,
		'categories' => $categories,
		'tags'       => $tags,
		'terms'      => $terms,
		'base_url'   => $base_url,
		'version'    => $wxr_version,
		'memory'     => $memory,
	);
}


/**
 * Check WordPress XML file for correct format
 *
 * @param string File path
 * @param boolean TRUE to halt process of error, FALSE to print out error
 * @return boolean TRUE on success, FALSE or HALT on errors
 */
function wpxml_check_xml_file( $file, $halt = false )
{
	// Enable XML error handling:
	$internal_errors = libxml_use_internal_errors( true );

	// Clear error of previous XML file (e.g. when ZIP archive has several XML files):
	libxml_clear_errors();

	// Register filter to avoid wrong chars in XML content:
	stream_filter_register( 'xmlutf8', 'ValidUTF8XMLFilter' );

	// Load XML content from file with xmlutf8 filter:
	$xml = simplexml_load_file( 'php://filter/read=xmlutf8/resource='.$file );

	if( ! $xml )
	{	// Halt/Display if loading produces an error:
		$errors = array();
		if( $halt )
		{	// Halt on error:
			foreach( libxml_get_errors() as $error )
			{
				$errors[] = 'Line '.$error->line.' - "'.format_to_output( $error->message, 'htmlspecialchars' ).'"';
			}
			debug_die( 'There was an error when reading XML file "'.$file.'".'
				.' Error: '.implode( ', ', $errors ) );
		}
		else
		{	// Display error:
			foreach( libxml_get_errors() as $error )
			{
				$errors[] = sprintf( 'Line %s', '<code>'.$error->line.'</code>' ).' - '.'"'.format_to_output( $error->message, 'htmlspecialchars' ).'"';
			}
			echo '<p class="text-danger">'.sprintf( 'There was an error when reading XML file %s.', '<code>'.$file.'</code>' ).'<br />'
				.sprintf( 'Error: %s', implode( ',<br />', $errors ) ).'</p>';
			return false;
		}
	}

	$r = false;
	if( $wxr_version = $xml->xpath( '/rss/channel/wp:wxr_version' ) )
	{	// Check WXR version for correct format:
		$wxr_version = (string) trim( $wxr_version[0] );
		$r = preg_match( '/^\d+\.\d+$/', $wxr_version );
	}
	elseif( $app_version = $xml->xpath( '/rss/channel/evo:app_version' ) )
	{	// Check application version for correct format:
		$app_version = (string) trim( $app_version[0] );
		$r = preg_match( '/^[\d\.]+(-[a-z]+)?$/i', $app_version );
	}
	

	if( ! $r )
	{	// If file format is wrong:
		if( $halt )
		{	// Halt on error:
			debug_die( 'This does not appear to be a XML file, missing/invalid WXR version number.' );
		}
		else
		{	// Display error:
			echo '<p class="text-danger">'.'This does not appear to be a XML file, missing/invalid WXR version number.'.'</p>';
			return false;
		}
	}

	return true;
}


/**
 * Get the unique url name
 *
 * @param string Source text
 * @param string Table name
 * @param string Field name
 * @return string category's url name
 */
function wp_unique_urlname( $source, $table, $field )
{
	global $DB;

	// Replace special chars/umlauts, if we can convert charsets:
	load_funcs( 'locales/_charset.funcs.php' );
	$url_name = strtolower( replace_special_chars( $source ) );

	$url_number = 1;
	$url_name_correct = $url_name;
	do
	{	// Check for unique url name in DB
		$SQL = new SQL( 'WordPress import: Check for unique url name in DB' );
		$SQL->SELECT( $field );
		$SQL->FROM( $table );
		$SQL->WHERE( $field.' = '.$DB->quote( $url_name_correct ) );
		$category = $DB->get_var( $SQL );
		if( $category )
		{	// Category already exists with such url name; Change it
			$url_name_correct = $url_name.'-'.$url_number;
			$url_number++;
		}
	}
	while( !empty( $category ) );

	return $url_name_correct;
}


/**
 * Get regional data (Used to get regional IDs for user & item by regional names)
 *
 * @param string Country code
 * @param string Region name
 * @param string Subregion name
 * @param string City name
 * @return array Regional data
 */
function wp_get_regional_data( $country_code, $region, $subregion, $city )
{
	$data = array(
			'country' => 0,
			'region' => 0,
			'subregion' => 0,
			'city' => 0,
		);

	if( !empty( $country_code ) )
	{	// Get country ID from DB by code
		$CountryCache = & get_CountryCache();
		if( $Country = & $CountryCache->get_by_name( $country_code, false ) )
		{
			$data['country'] = $Country->ID;

			if( !empty( $region ) )
			{	// Get region ID from DB by name
				$RegionCache = & get_RegionCache();
				if( $Region = & $RegionCache->get_by_name( $region, false ) )
				{
					if( $Region->ctry_ID == $data['country'] )
					{
						$data['region'] = $Region->ID;

						if( !empty( $subregion ) )
						{	// Get subregion ID from DB by name
							$SubregionCache = & get_SubregionCache();
							if( $Subregion = & $SubregionCache->get_by_name( $subregion, false ) )
							{
								if( $Subregion->rgn_ID == $data['region'] )
								{
									$data['subregion'] = $Subregion->ID;
								}
							}
						}

						if( !empty( $city ) )
						{	// Get city ID from DB by name
							$CityCache = & get_CityCache();
							if( $City = & $CityCache->get_by_name( $city, false ) )
							{
								if( $City->rgn_ID == $data['region'] )
								{
									$data['city'] = $City->ID;
								}
							}
						}
					}
				}
			}
		}
	}

	return $data;
}


/**
 * Convert string value to normal encoding
 *
 * @param string Value
 * @return string A converted value
 */
function wpxml_convert_value( $value )
{
	global $xml_import_convert_to_latin;

	$value = (string) $value;

	if( $xml_import_convert_to_latin )
	{ // We should convert a value from utf8 to latin1
		if( function_exists( 'iconv' ) )
		{ // Convert by iconv extenssion
			$value = iconv( 'utf-8', 'iso-8859-1', $value );
		}
		elseif( function_exists( 'mb_convert_encoding' ) )
		{ // Convert by mb extenssion
			$value = mb_convert_encoding( $value, 'iso-8859-1', 'utf-8' );
		}
	}

	return $value;
}


/**
 * Create object File from source path
 *
 * @param string Source file path
 * @param array Params
 * @return object|boolean File or FALSE
 */
function & wpxml_create_File( $file_source_path, $params )
{
	$params = array_merge( array(
			'file_root_type' => 'collection',
			'file_root_ID'   => '',
			'file_path'      => '',
			'file_title'     => '',
			'file_alt'       => '',
			'file_desc'      => '',
		), $params );

	// Set false to return failed result by reference
	$File = false;

	if( ! file_exists( $file_source_path ) )
	{	// File doesn't exist
		echo '<p class="text-warning">'.sprintf( 'Unable to copy file %s, because it does not exist.', '<code>'.$file_source_path.'</code>' ).'</p>';
		// Skip it:
		return $File;
	}

	// Get FileRoot by type and ID
	$FileRootCache = & get_FileRootCache();
	$FileRoot = & $FileRootCache->get_by_type_and_ID( $params['file_root_type'], $params['file_root_ID'] );

	// Get file name with a fixed name if file with such name already exists in the destination path:
	$dest_file = basename( $params['file_path'] );
	$dest_folder = dirname( $params['file_path'] );
	if( $dest_folder == '.' )
	{
		$dest_folder = '/';
	}
	list( $File, $old_file_thumb ) = check_file_exists( $FileRoot, $dest_folder, $dest_file );

	if( ! $File || ! copy_r( $file_source_path, $File->get_full_path() ) )
	{	// No permission to copy to the destination folder
		if( is_dir( $file_source_path ) )
		{	// Folder
			echo '<p class="text-warning">'.sprintf( 'Unable to copy folder %s to %s. Please, check the permissions assigned to this folder.', '<code>'.$file_source_path.'</code>', '<code>'.$File->get_full_path().'</code>' ).'</p>';
		}
		else
		{	// File
			echo '<p class="text-warning">'.sprintf( 'Unable to copy file %s to %s. Please, check the permissions assigned to this file.', '<code>'.$file_source_path.'</code>', '<code>'.$File->get_full_path().'</code>' ).'</p>';
		}
		// Skip it:
		return $File;
	}

	// Set additional params for new creating File object:
	$File->set( 'title', $params['file_title'] );
	$File->set( 'alt', $params['file_alt'] );
	$File->set( 'desc', $params['file_desc'] );
	$File->dbsave();

	echo '<p class="text-success">'.sprintf( 'File %s has been imported to %s successfully.', '<code>'.$file_source_path.'</code>', '<code>'.$File->get_full_path().'</code>' ).'</p>';

	evo_flush();

	return $File;
}


/**
 * This class is used to avoid wrong chars in XML files on import
 *
 * @see wpxml_parser()
 * @see wpxml_check_xml_file()
 */
class ValidUTF8XMLFilter extends php_user_filter
{
	protected static $pattern = '/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u';

	function filter( $in, $out, & $consumed, $closing )
	{
		while( $bucket = stream_bucket_make_writeable( $in ) )
		{
			$bucket->data = preg_replace( self::$pattern, '', $bucket->data );
			$consumed += $bucket->datalen;
			stream_bucket_append( $out, $bucket );
		}
		return PSFS_PASS_ON;
	}
}


/**
 * Display info for the wordpress importer
 *
 * @param boolean TRUE to allow to use already extracted ZIP archive
 * @return array Data of the parsed XML file, @see wpxml_get_import_data()
 */
function wpxml_info( $allow_use_extracted_folder = false )
{
	evo_flush();

	echo '<p>';

	$wp_file = get_param( 'import_file' );

	if( preg_match( '/\.zip$/i', $wp_file ) )
	{	// Inform about unzipping before start this in wpxml_get_import_data():
		$zip_folder_path = substr( $wp_file, 0, -4 );
		if( ! $allow_use_extracted_folder ||
		    ! file_exists( $zip_folder_path ) ||
		    ! is_dir( $zip_folder_path ) )
		{
			echo '<b>'.TB_('Unzipping ZIP').':</b> <code>'.$wp_file.'</code>...<br />';
			evo_flush();
		}
	}

	// Get data to import from wordpress XML file:
	$wpxml_import_data = wpxml_get_import_data( $wp_file, $allow_use_extracted_folder );

	if( $wpxml_import_data['errors'] === false )
	{
		if( preg_match( '/\.zip$/i', $wp_file ) )
		{	// ZIP archive:
			echo '<b>'.TB_('Source ZIP').':</b> <code>'.$wp_file.'</code><br />';
			// XML file from ZIP archive:
			echo '<b>'.TB_('Source XML').':</b> '
				.( empty( $wpxml_import_data['XML_file_path'] )
					? T_('Not found')
					: '<code>'.$wpxml_import_data['XML_file_path'].'</code>' ).'<br />';
		}
		else
		{	// XML file:
			echo '<b>'.TB_('Source XML').':</b> <code>'.$wp_file.'</code><br />';
		}

		echo '<b>'.TB_('Source attachments folder').':</b> '
			.( empty( $wpxml_import_data['attached_files_path'] )
				? T_('Not found')
				: '<code>'.$wpxml_import_data['attached_files_path'].'</code>' ).'<br />';

		$BlogCache = & get_BlogCache();
		$Collection = $Blog = & $BlogCache->get_by_ID( get_param( 'wp_blog_ID' ) );
		$wpxml_import_data['Blog'] = & $Blog;
		echo '<b>'.TB_('Destination collection').':</b> '.$Blog->dget( 'shortname' ).' &ndash; '.$Blog->dget( 'name' ).'<br />';

		echo '<b>'.TB_('Import mode').':</b> ';
		switch( get_param( 'import_type' ) )
		{
			case 'append':
				echo TB_('Append to existing contents');
				break;
			case 'replace':
				echo TB_('Replace existing contents').' <span class="note">'.TB_('WARNING: this option will permanently remove existing posts, comments, categories and tags from the selected collection.').'</span>';
				if( get_param( 'delete_files' ) )
				{
					echo '<br /> &nbsp; &nbsp; [√] '.TB_(' Also delete media files that will no longer be referenced in the destination collection after replacing its contents');
				}
				break;
		}
		echo '<br />';

		if( get_param( 'import_img' ) )
		{
			echo '<b>'.TB_('Options').':</b> [√] '.TB_('Try to match any remaining <code>&lt;img&gt;</code> tags with imported attachments based on filename');
		}
	}
	else
	{	// Display errors if import cannot be done:
		echo $wpxml_import_data['errors'];
		echo '<br /><p class="text-danger">'.T_('Import failed.').'</p>';
	}

	echo '</p>';

	return $wpxml_import_data;
}


/**
 * Display a selector for Item Types
 *
 * @param string XML file path
 * @param string|NULL Temporary folder of unpacked ZIP archive
 */
function wpxml_item_types_selector( $XML_file_path, $ZIP_folder_path = NULL )
{
	// Parse WordPress XML file into array
	echo 'Loading & parsing the XML file...'.'<br />';
	evo_flush();
	$xml_data = wpxml_parser( $XML_file_path );
	echo '<ul class="list-default">';
		echo '<li>'.'Memory used by XML parsing (difference between free RAM before loading XML and after)'.': <b>'.bytesreadable( $xml_data['memory']['parsing'] ).'</b></li>';
		echo '<li>'.'Memory used by temporary arrays (difference between free RAM after loading XML and after copying all the various data into temporary arrays)'.': <b>'.bytesreadable( $xml_data['memory']['arrays'] ).'</b></li>';
	echo '</ul>';
	evo_flush();

	$item_type_names = array();
	$item_type_usages = array();
	$no_item_types = 0;
	if( ! empty( $xml_data['posts'] ) )
	{	// Count items number per item type:
		foreach( $xml_data['posts'] as $post )
		{
			if( $post['post_type'] == 'attachment' || $post['post_type'] == 'revision' )
			{	// Skip reserved post type:
				continue;
			}

			if( ! empty( $post['itemtype'] ) )
			{	// Use evo field Item Type name as first priority:
				if( ! isset( $item_type_names[ $post['itemtype'] ] ) )
				{
					$item_type_names[ $post['itemtype'] ] = 1;
				}
				else
				{
					$item_type_names[ $post['itemtype'] ]++;
				}
			}
			elseif( ! empty( $post['post_type'] ) )
			{	// Use wp field Item Type usage as second priority:
				if( ! isset( $item_type_usages[ $post['post_type'] ] ) )
				{
					$item_type_usages[ $post['post_type'] ] = 1;
				}
				else
				{
					$item_type_usages[ $post['post_type'] ]++;
				}
			}
			else
			{	// If Item Type is not defined at all:
				$no_item_types++;
			}
		}
	}

	if( empty( $item_type_names ) && empty( $item_type_usages ) && $no_item_types == 0 )
	{	// No posts:
		echo '<p>No posts found in XML file, you can try to import other data like catefories and etc.</p>';
	}
	else
	{	// Display Item Types selectors:
		$ItemTypeCache = & get_ItemTypeCache();
		$ItemTypeCache->clear();
		$SQL = $ItemTypeCache->get_SQL_object();
		$SQL->FROM_add( 'INNER JOIN T_items__type_coll ON itc_ityp_ID = ityp_ID' );
		$SQL->WHERE( 'itc_coll_ID = '.get_param( 'wp_blog_ID' ) );
		$ItemTypeCache->load_by_sql( $SQL );

		echo '<b>'.TB_('Select item types:').'</b>';
		echo '<ul class="list-default controls">';
		// Selector for Item Types by name:
		wpxml_display_item_type_selector( $item_type_names, 'name' );
		// Selector for Item Types by usage:
		wpxml_display_item_type_selector( $item_type_usages, 'usage' );
		if( $no_item_types > 0 )
		{	// Selector for without provided Item Types:
			wpxml_display_item_type_selector( array( $no_item_types ), 'none' );
		}
		echo '</ul>';
	}
}


/**
 * Display item type selector
 *
 * @param array
 */
function wpxml_display_item_type_selector( $item_types, $item_type_field )
{
	$ItemTypeCache = & get_ItemTypeCache();

	foreach( $item_types as $item_type => $items_num )
	{
		echo '<li>';
		switch( $item_type_field )
		{
			case 'name':
				printf( '%d items with %s -> import as', $items_num, '<code>&lt;evo:itemtype&gt;</code> = <code>'.$item_type.'</code>' );
				$form_field_name = 'item_type_names['.$item_type.']';
				break;
			case 'usage':
				printf( '%d items with %s -> import as', $items_num, '<code>&lt;wp:post_type&gt;</code> = <code>'.$item_type.'</code>' );
				$form_field_name = 'item_type_usages['.$item_type.']';
				break;
			case 'none':
				printf( '%d items without provided item type -> import as', $items_num );
				$form_field_name = 'item_type_none';
				break;
		}
		echo ' <select name="'.$form_field_name.'" class="form-control" style="margin:2px">'
					.'<option value="0">'.format_to_output( TB_('Do not import') ).'</option>';
		$is_auto_selected = false;
		$is_first_selected = false;
		foreach( $ItemTypeCache->cache as $ItemType )
		{
			if( $item_type_field != 'none' &&
			    ! $is_first_selected &&
			    $ItemType->get( $item_type_field ) == $item_type )
			{
				$is_auto_selected = true;
				$is_first_selected = true;
			}
			else
			{
				$is_auto_selected = false;
			}
			echo '<option value="'.$ItemType->ID.'"'.( $is_auto_selected ? ' selected="selected"' : '' ).'>'.format_to_output( $ItemType->get( 'name' ) ).'</option>';
		}
		echo '</select>'
			.'</li>';
	}
}
?>