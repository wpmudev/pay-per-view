<?php
/**
*	Uninstalls plugin (deletes options, metas and removes database table)
*/
function ppw_uninstall() {
	delete_option( 'ppw_options' );

	global $wpdb;
	$table = $wpdb->prefix . "pay_per_view";

	if( $wpdb->get_var("SHOW TABLES LIKE '". $table. "'") == $table )
	$wpdb->query( "DROP TABLE " . $table );

	$wpdb->query("DELETE FROM " . $wpdb->usermeta . " WHERE meta_key='ppw_subscribe' OR meta_key='ppw_recurring' OR meta_key='ppw_days' " );
	$wpdb->query("DELETE FROM " . $wpdb->postmeta . " WHERE meta_key='ppw_method' OR meta_key='ppw_enable' OR meta_key='ppw_excerpt' OR meta_key='ppw_price' " );
}