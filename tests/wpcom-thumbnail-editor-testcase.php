<?php

/**
 * Base unit test class for WordPress.com Thumbnail Editor
 */
class WPCOMThumbnailEditor_TestCase extends WP_UnitTestCase {
	public function setUp() {
		parent::setUp();

		global $wpcom_thumbnail_editor;
		$this->_toc = $wpcom_thumbnail_editor;
	}
}
