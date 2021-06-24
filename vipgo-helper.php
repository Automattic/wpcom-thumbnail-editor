<?php
/**
 * VIP Go Helper
 *
 * @package WPCOM_Thumbnail_Editor
 */

add_filter(
	'jetpack_photon_domain',
	function() {
		return home_url();
	}
);
