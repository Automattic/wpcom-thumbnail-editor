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

	/**
	 * @dataProvider data_provider_maintain_thumbnail_crop_in_srcset
	 */
	public function test_maintain_thumbnail_crop_in_srcset(
		$expected,
		$input
	) {
		// Prevent Photon from bailing due to environment.
		add_filter( 'jetpack_photon_development_mode', '__return_false', 999 );

		/**
		 * Mirror VIP Go functionality to simplify testing, otherwise
		 * `example.com` is used for the Photon host and `ssl=1` query string is
		 * appended to all URLs.
		 */
		add_filter(
			'jetpack_photon_domain',
			static function() {
				return 'https://wpvip.com';
			}
		);

		list( $sources, $src ) = $input;

		$this->assertEquals(
			$expected,
			$this->_toc->maintain_thumbnail_crop_in_srcset(
				$sources,
				[], // Unused argument.
				$src
			)
		);
	}

	/**
	 * Data provider to test `::maintain_thumbnail_crop_in_srcset()`
	 *
	 * @return array
	 */
	public function data_provider_maintain_thumbnail_crop_in_srcset() {
		$base_url             = 'https://wpvip.com/image.jpg';
		$crop_string          = '?crop=249px%2C0px%2C4075px%2C2717px';
		$base_resize_string   = '&resize=100%2C50';
		$larger_resize_string = '&resize=200%2C100';
		$width_string         = '&w=100';

		$base_sources = [
			'url'        => $base_url,
			'descriptor' => 'w',
			'value'      => 100,
		];

		return [
			'Empty sources'                 => [
				[],
				[
					[],
					$base_url,
				],
			],
			'No query strings'              => [
				[
					$base_sources,
				],
				[
					[
						$base_sources,
					],
					$base_url,
				],
			],
			'No crop strings'               => [
				[
					$base_sources,
				],
				[
					[
						$base_sources,
					],
					add_query_arg( 'foo', 'bar', $base_url ),
				],
			],
			'Pixel-density descriptor'      => [
				[
					array_replace(
						$base_sources,
						[
							'descriptor' => 'x',
						],
					),
				],
				[
					[
						array_replace(
							$base_sources,
							[
								'descriptor' => 'x',
							],
						),
					],
					$base_url . $crop_string,
				],
			],
			'Already cropped'               => [
				[
					array_replace(
						$base_sources,
						[
							'url' => $base_url . $crop_string,
						],
					),
				],
				[
					[
						array_replace(
							$base_sources,
							[
								'url' => $base_url . $crop_string,
							],
						),
					],
					$base_url . $crop_string,
				],
			],
			'Crop added'                    => [
				[
					array_replace(
						$base_sources,
						[
							'url' => $base_url . $crop_string . $base_resize_string,
						],
					),
				],
				[
					[
						$base_sources,
					],
					$base_url . $crop_string . $base_resize_string,
				],
			],
			'Crop and width added'          => [
				[
					array_replace(
						$base_sources,
						[
							'url' => $base_url . $crop_string . $larger_resize_string . $width_string,
						],
					),
				],
				[
					[
						$base_sources,
					],
					$base_url . $crop_string . $larger_resize_string,
				],
			],
			'Crop added and fit removed'    => [
				[
					array_replace(
						$base_sources,
						[
							'url' => $base_url . $crop_string . $base_resize_string,
						],
					),
				],
				[
					[
						array_replace(
							$base_sources,
							[
								'url' => $base_url . '?fit=50%2C50',
							]
						),
					],
					$base_url . $crop_string . $base_resize_string,
				],
			],
			'Crop added, quality unchanged' => [
				[
					array_replace(
						$base_sources,
						[
							'url' => $base_url . $crop_string . $base_resize_string . '&quality=75',
						],
					),
				],
				[
					[
						array_replace(
							$base_sources,
							[
								'url' => $base_url . '?quality=75',
							]
						),
					],
					$base_url . $crop_string . $base_resize_string,
				],
			],
			'Fit loses width'               => [
				[
					array_replace(
						$base_sources,
						[
							'url' => $base_url . $crop_string . '&fit=50%2C50',
						],
					),
				],
				[
					[
						array_replace(
							$base_sources,
							[
								'url' => $base_url . '?fit=50%2C50' . $width_string,
							]
						),
					],
					$base_url . $crop_string,
				],
			],
		];
	}
}
