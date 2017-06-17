<?php # -*- coding: utf-8 -*-

$strings = 'tinyMCE.addI18n( "' . _WP_Editors::$mce_locale . '.ppw_lang", {
    description: "' . esc_js( __( 'Description', 'ppw' ) ) . '",
    price: "' . esc_js( __( 'Price', 'ppw' ) ) . '",
    insert: "' . esc_js( __( 'Insert', 'ppw' ) ) . '",
    cancel: "' . esc_js( __( 'Cancel', 'ppw' ) ) . '",
    invalid_number: "' . esc_js( __( 'You need to insert a valid number in the Price field', 'ppw' ) ) . '",
} )';