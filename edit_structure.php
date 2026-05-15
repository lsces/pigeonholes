<?php
/**
 * $Header$
 *
 * Copyright ( c ) 2004 bitweaver.org
 * Copyright ( c ) 2003 tikwiki.org
 * Copyright ( c ) 2002-2003, Luis Argerich, Garland Foster, Eduardo Polidor, et. al.
 * All Rights Reserved. See below for details and a complete list of authors.
 * Licensed under the GNU LESSER GENERAL PUBLIC LICENSE. See http://www.gnu.org/copyleft/lesser.html for details
 *
 * $Id$
 * @package pigeonholes
 * @subpackage functions
 */

/**
 * required setup
 */
require_once '../kernel/includes/setup_inc.php';

$gBitSystem->verifyPackage( 'pigeonholes' );
$gBitSystem->verifyPermission( 'p_pigeonholes_create' );

// we need to load some javascript and css for this page
$gBitThemes->loadCss( PIGEONHOLES_PKG_PATH.'scripts/DynamicTree.css' );
$gBitThemes->loadJavascript( PIGEONHOLES_PKG_PATH.'scripts/DynamicTreeBuilder.js' );

include_once PIGEONHOLES_PKG_INCLUDE_PATH.'lookup_pigeonholes_inc.php';

$verifyStructurePermission = 'p_pigeonholes_create';
include_once LIBERTY_PKG_INCLUDE_PATH.'structure_edit_inc.php';

// Display the template
$gBitSystem->display( 'bitpackage:pigeonholes/edit_structure.tpl', $gStructure->mInfo["title"] , [ 'display_mode' => 'edit' ]);