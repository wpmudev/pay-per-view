<?php
/**
 * Dynamically generate the TinyMCE localization file if it doesn't already exist
 */
$lang_file = dirname(__FILE__) . '/' . $mce_locale . '_dlg.js';

if ( is_file($lang_file) && is_readable($lang_file) ) {
	$strings = get_file_contents($lang_file);
} else {
	$strings = get_file_contents(dirname(__FILE__) . '/en_dlg.js');
	$strings = preg_replace( '/([\'"])en\./', '$1'.$mce_locale.'.', $strings, 1 );
}
