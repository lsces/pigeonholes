<?php
/**
 * @author   xing <xing@synapse.plus.com>
 * @version  $Revision$
 * @package  Pigeonholes
 * @subpackage functions
 */
global $gBitSystem, $gBitUser, $gLibertySystem;

$pRegisterHash = [
	'package_name' => 'pigeonholes',
	'package_path' => dirname( dirname( __FILE__ ) ).'/',
	'service' => LIBERTY_SERVICE_CATEGORIZATION,
];

// fix to quieten down VS Code which can't see the dynamic creation of these ...
define( 'PIGEONHOLES_PKG_NAME', $pRegisterHash['package_name'] );
define( 'PIGEONHOLES_PKG_URL', BIT_ROOT_URL . basename( $pRegisterHash['package_path'] ) . '/' );
define( 'PIGEONHOLES_PKG_PATH', BIT_ROOT_PATH . basename( $pRegisterHash['package_path'] ) . '/' );
define( 'PIGEONHOLES_PKG_INCLUDE_PATH', BIT_ROOT_PATH . basename( $pRegisterHash['package_path'] ) . '/includes/'); 
define( 'PIGEONHOLES_PKG_CLASS_PATH', BIT_ROOT_PATH . basename( $pRegisterHash['package_path'] ) . '/includes/classes/');
define( 'PIGEONHOLES_PKG_ADMIN_PATH', BIT_ROOT_PATH . basename( $pRegisterHash['package_path'] ) . '/admin/'); 

$gBitSystem->registerPackage( $pRegisterHash );

define( 'PIGEONHOLES_CONTENT_TYPE_GUID', 'pigeonholes' );

if( $gBitSystem->isPackageActive( 'pigeonholes' )) {

	$tpl = $gBitSystem->isFeatureActive( 'pigeonholes_use_jstab' ) ? 'tab' : 'mini';
	$gLibertySystem->registerService( LIBERTY_SERVICE_CATEGORIZATION, PIGEONHOLES_PKG_NAME, [
		// functions
		'content_display_function'  => 'pigeonholes_content_display',
		'content_preview_function'  => 'pigeonholes_content_preview',
		'content_edit_function'     => 'pigeonholes_content_edit',
		'content_store_function'    => 'pigeonholes_content_store',
		'content_expunge_function'  => 'pigeonholes_content_expunge',
		'content_list_function'     => 'pigeonholes_content_list',
		'content_list_sql_function' => 'pigeonholes_content_list_sql',

		// templates
		'content_edit_'.$tpl.'_tpl' => 'bitpackage:pigeonholes/service_edit_'.$tpl.'_inc.tpl',
		'content_view_tpl'          => 'bitpackage:pigeonholes/service_view_members_inc.tpl',
		'content_nav_tpl'           => 'bitpackage:pigeonholes/service_nav_path_inc.tpl',
		'content_list_options_tpl'  => 'bitpackage:pigeonholes/service_list_options_inc.tpl',
	] );

	if( $gBitUser->hasPermission( 'p_pigeonholes_view' )) {
		$menuHash = [
			'package_name'  => PIGEONHOLES_PKG_NAME,
			'index_url'     => PIGEONHOLES_PKG_URL.'index.php',
			'menu_template' => 'bitpackage:pigeonholes/menu_pigeonholes.tpl',
		];
		$gBitSystem->registerAppMenu( $menuHash );
	}
}