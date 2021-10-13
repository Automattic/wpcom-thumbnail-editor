<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Plugin Name:  WordPress.com Thumbnail Editor
 * Version:      1.0.1
 * Description:  Since thumbnails are generated on-demand on WordPress.com, thumbnail cropping location must be set via the URL. This plugin assists in doing this. Based on concepts by Imran Nathani of <a href="http://metronews.ca/">Metro News Canada</a>.
 * Author:       Automattic
 * Author URI:   https://wpvip.com/
 *
 * @package WPCOM_Thumbnail_Editor
 */

/**
 * Plugin container
 */
class WPcom_Thumbnail_Editor {

	/**
	 * Post meta key name, for storing crop coordinates.
	 *
	 * @var string
	 */
	public $post_meta = 'wpcom_thumbnail_edit';

	/**
	 * Determine if we want to use a dimension map or not
	 *
	 * @var bool
	 */
	public $use_ratio_map = false;

	/**
	 * An array that maps specific aspect ratios to image size strings. Should be defined by user to be accurate.
	 *
	 * @var array
	 */
	public $image_ratio_map = array();

	/**
	 * Default settings for allowing private blogs to use this plugin.
	 *
	 * @var bool
	 */
	public $allow_private_blogs = false;

	/**
	 * Initialize the class by registering various hooks.
	 */
	public function __construct() {
		$args = apply_filters(
			'wpcom_thumbnail_editor_args',
			array(
				'image_ratio_map' => false,
			)
		);

		// Allow for private blogs to use this plugin.
		$this->allow_private_blogs = apply_filters( 'wpcom_thumbnail_editor_allow_private_blogs', $this->allow_private_blogs );

		// When a thumbnail is requested, intercept the request and return the custom thumbnail.
		if ( ! function_exists( 'is_private_blog' ) || ( function_exists( 'is_private_blog' )
			&& ( ! is_private_blog() || true === $this->allow_private_blogs ) ) ) {
			add_filter( 'image_downsize', array( &$this, 'get_thumbnail_url' ), 15, 3 );

			// Run late to avoid overwriting modifications made at default priority.
			add_filter( 'wp_calculate_image_srcset', [ $this, 'maintain_thumbnail_crop_in_srcset' ], 20, 3 );
		}

		// Admin-only hooks.
		if ( is_admin() ) {

			// Add a new field to the edit attachment screen.
			add_filter( 'attachment_fields_to_edit', array( &$this, 'add_attachment_fields_to_edit' ), 50, 2 );

			// Create a new screen for editing a thumbnail.
			add_action( 'admin_action_wpcom_thumbnail_edit', array( &$this, 'edit_thumbnail_screen' ) );

			// Handle the form submit of the edit thumbnail screen.
			add_action( 'admin_post_wpcom_thumbnail_edit', array( &$this, 'post_handler' ) );

			// Display status messages.
			if ( ! empty( $_GET['wtereset'] ) || ! empty( $_GET['wteupdated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				add_action( 'admin_notices', array( &$this, 'output_thumbnail_message' ) );
			}

			add_action( 'admin_notices', array( &$this, 'jetpack_photon_url_message' ) );
		}

		// using a global for now, maybe these values could be set in constructor in future?
		if ( is_array( $args['image_ratio_map'] ) ) {
			$this->use_ratio_map = true;

			$ratio_map = array();

			// Validate image sizes.
			global $_wp_additional_image_sizes;
			foreach ( $args['image_ratio_map'] as $ratio => $image_sizes ) {
				$ratio_map[ $ratio ] = array();

				foreach ( $image_sizes as $image_size ) {
					if ( array_key_exists( $image_size, $_wp_additional_image_sizes ) ) {
						$ratio_map[ $ratio ][] = $image_size;
					}
				}

				if ( empty( $ratio_map[ $ratio ] ) ) {
					unset( $ratio_map[ $ratio ] );
				}
			}
			$this->image_ratio_map = $ratio_map;
		}
	}

	/**
	 * Outputs status messages based on query parameters.
	 *
	 * It cheats a little and uses the settings error API in order to avoid having to generate it's own HTML.
	 */
	public function output_thumbnail_message() {
		if ( ! empty( $_GET['wtereset'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_settings_error( 'wpcom_thumbnail_edit', 'reset', __( 'Thumbnail position reset.', 'wpcom-thumbnail-editor' ), 'updated' );
		} elseif ( ! empty( $_GET['wteupdated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_settings_error( 'wpcom_thumbnail_edit', 'updated', __( 'Thumbnail position updated.', 'wpcom-thumbnail-editor' ), 'updated' );
		} else {
			return;
		}

		settings_errors( 'wpcom_thumbnail_edit' );
	}

	/**
	 * Display a message if JetPack isn't enabled (specifically, jetpack_photon_url is not defined.)
	 */
	public function jetpack_photon_url_message() {
		if ( function_exists( 'jetpack_photon_url' ) ) {
			return;
		}

		echo '<div class="error"><p>' . esc_html__( 'Jetpack is not enabled, which will disable some features of the WordPress.com Thumbnail Editor module. Please enable JetPack to make this module fully functional.', 'wpcom-thumbnail-editor' ) . '</p></div>';

		settings_errors( 'wpcom_thumbnail_edit' );
	}

	/**
	 * Adds a new field to the edit attachment screen that lists thumbnail sizes.
	 *
	 * @param array  $form_fields Existing fields.
	 * @param object $attachment The attachment currently being edited.
	 * @return array Form fields, either unmodified on error or new field added on success.
	 */
	public function add_attachment_fields_to_edit( $form_fields, $attachment ) {
		if ( ! wp_attachment_is_image( $attachment->ID ) ) {
			return $form_fields;
		}

		$form_fields['wpcom_thumbnails'] = array(
			'label' => 'Thumbnail Images',
			'input' => 'html',
			'html'  => $this->get_attachment_field_html( $attachment ),
		);

		return $form_fields;
	}

	/**
	 * Generates the HTML for the edit attachment field.
	 *
	 * @param object $attachment The attachment currently being edited.
	 * @return string The HTML for the form field.
	 */
	public function get_attachment_field_html( $attachment ) {
		$sizes = $this->use_ratio_map ? $this->get_image_sizes_by_ratio() : $this->get_intermediate_image_sizes();

		$sizes = apply_filters( 'wpcom_thumbnail_editor_image_size_names_choose', $sizes );

		if ( empty( $sizes ) ) {
			return '<p>' . __( 'No thumbnail sizes could be found that are cropped. For now this functionality only supports cropped thumbnails.', 'wpcom-thumbnail-editor' ) . '</p>';
		}

		// Photon has to be able to access the source images.
		if ( function_exists( 'is_private_blog' ) && is_private_blog() && true !== $this->allow_private_blogs ) {
			/* translators: 1: URL */
			return '<p>' . sprintf( __( "The WordPress.com VIP custom thumbnail cropping functionality doesn't work on sites <a href='%s'>marked as private</a>.", 'wpcom-thumbnail-editor' ), admin_url( 'options-reading.php' ) ) . '</p>';
		} elseif ( 'localhost' == filter_input( INPUT_SERVER, 'HTTP_HOST', FILTER_SANITIZE_STRING ) ) {
			return '<p>' . __( "The WordPress.com VIP custom thumbnail cropping functionality needs the images be publicly accessible in order to work, which isn't possible when you're developing locally.", 'wpcom-thumbnail-editor' ) . '</p>';
		}

		$html = '<p class="hide-if-js">' . __( 'You need to enable Javascript to use this functionality.', 'wpcom-thumbnail-editor' ) . '</p>';

		$html .= '<input type="button" class="hide-if-no-js button" onclick="jQuery(this).hide();jQuery(\'#' . esc_js( 'wpcom-thumbs-' . $attachment->ID ) . '\').slideDown(\'slow\');" value="' . __( 'Show Thumbnails', 'wpcom-thumbnail-editor' ) . '" />';

		$html .= '<div id="' . esc_attr( 'wpcom-thumbs-' . $attachment->ID ) . '" class="hidden">';

			$html .= '<p>' . __( 'Click on a thumbnail image to modify it. Each thumbnail has likely been scaled down in order to fit nicely into a grid.<br /><strong>Only thumbnails that are cropped are shown.</strong> Other sizes are hidden because they will be scaled to fit.', 'wpcom-thumbnail-editor' ) . '</p>';

			$html .= '<div>';

		// key wont really matter if its not using a dimension map.
		foreach ( $sizes as $key => $size ) {
			$image_name = $this->use_ratio_map ? $key : $size;
			$image_name = apply_filters( 'wpcom_thumbnail_editor_image_name', $image_name, $key, $size, $this->use_ratio_map );

			$edit_url = admin_url( 'admin.php?action=wpcom_thumbnail_edit&id=' . intval( $attachment->ID ) . '&size=' . urlencode( $size ) );

			// add an extra query var if were using a ratio map.
			if ( $this->use_ratio_map ) {
				$edit_url = add_query_arg( 'ratio', $key, $edit_url );
			}

			// We need to get the fullsize thumbnail so that the cropping is properly done.
			$thumbnail = image_downsize( $attachment->ID, $size );

			// Resize the thumbnail to fit into a small box so it's displayed at a reasonable size.
			if ( function_exists( 'jetpack_photon_url' ) ) {
				$thumbnail_url = jetpack_photon_url(
					$thumbnail[0],
					apply_filters( 'wpcom_thumbnail_editor_preview_args', array( 'fit' => array( 250, 250 ) ), $attachment->ID, $size )
				);
			} else {
				$thumbnail_url = $thumbnail[0];
			}

			$html     .= '<div style="float:left;margin:0 20px 20px 0;min-width:250px;">';
				$html .= '<a href="' . esc_url( $edit_url ) . '"';


			if ( 'media.php' != basename( filter_input( INPUT_SERVER, 'PHP_SELF', FILTER_SANITIZE_STRING ) ) ) {
				$html .= ' target="_blank"';
			}

				$html     .= '>';
					$html .= '<strong>' . esc_html( $image_name ) . '</strong><br />';
					$html .= '<img src="' . esc_url( $thumbnail_url ) . '" alt="' . esc_attr( $size ) . '" />';
				$html     .= '</a>';
			$html         .= '</div>';
		}

			$html .= '</div>';
		$html     .= '</div>';

		return $html;
	}

	/**
	 * Outputs the HTML for the thumbnail crop selection screen.
	 */
	public function edit_thumbnail_screen() {
		global $parent_file, $submenu_file, $title;

		// Validate "id" and "size" query string values and check user capabilities. Dies on error.
		$attachment = $this->validate_parameters();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		$size = $_REQUEST['size']; // Validated in this::validate_parameters().

		// Make sure the image fits on the screen.
		$image = image_downsize( $attachment->ID, array( 1024, 1024 ) );
		if ( ! $image ) {
			wp_die( esc_html__( 'Failed to downsize the original image to fit on your screen. How odd. Please contact support.', 'wpcom-thumbnail-editor' ) );
		}

		// How big is the final thumbnail image?
		$thumbnail_dimensions = $this->get_thumbnail_dimensions( $size );
		if ( ! $thumbnail_dimensions ) {
			/* translators: 1: parameter markup */
			wp_die( sprintf( esc_html__( 'Invalid %s parameter.', 'wpcom-thumbnail-editor' ), '<code>size</code>' ) );
		}

		$parent_file  = 'upload.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$submenu_file = 'upload.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		// adjust the image name if were using a ratio map.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$image_name = isset( $_REQUEST['ratio'] ) ? sanitize_text_field( $_REQUEST['ratio'] ) : $size;

		/* translators: 1: image name */
		$title = sprintf( esc_html__( 'Edit Thumbnail: %s', 'wpcom-thumbnail-editor' ), $image_name ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		wp_enqueue_script( 'imgareaselect' );
		wp_enqueue_style( 'imgareaselect' );

		require ABSPATH . '/wp-admin/admin-header.php';

		$original_aspect_ratio  = $image[1] / $image[2];
		$thumbnail_aspect_ratio = $thumbnail_dimensions['width'] / $thumbnail_dimensions['height'];

		// Build the selection coordinates.

		// If there's already a custom selection.
		$coordinates = $this->get_coordinates( $attachment->ID, $size );
		if ( $coordinates ) {
			$attachment_metadata = wp_get_attachment_metadata( $attachment->ID );

			// If original is bigger than display, scale down the coordinates to match the scaled down original.
			if ( $attachment_metadata['width'] > $image[1] || $attachment_metadata['height'] > $image[2] ) {

				// At what percentage is the image being displayed at?
				$scale = $image[1] / $attachment_metadata['width'];

				foreach ( $coordinates as $coordinate ) {
					$initial_selection[] = round( $coordinate * $scale );
				}
			} else {
				// Or the image was not downscaled, so the coordinates are correct.

				$initial_selection = $coordinates;
			}
		} elseif ( $thumbnail_aspect_ratio == $original_aspect_ratio ) {
			// If original and thumb are the same aspect ratio, then select the whole image.

			$initial_selection = array( 0, 0, $image[1], $image[2] );
		} elseif ( $thumbnail_aspect_ratio > $original_aspect_ratio ) {
			// If the thumbnail is wider than the original, we want the full width.

			// Take the width and divide by the thumbnail's aspect ratio.
			$selected_height = round( $image[1] / ( $thumbnail_dimensions['width'] / $thumbnail_dimensions['height'] ) );

			$initial_selection = array(
				0,                                                     // Far left edge (due to aspect ratio comparison).
				round( ( $image[2] / 2 ) - ( $selected_height / 2 ) ), // Mid-point + half of height of selection.
				$image[1],                                             // Far right edge (due to aspect ratio comparison).
				round( ( $image[2] / 2 ) + ( $selected_height / 2 ) ), // Mid-point - half of height of selection.
			);
		} else {
			// The thumbnail must be narrower than the original, so we want the full height.

			// Take the width and divide by the thumbnail's aspect ratio.
			$selected_width = round( $image[2] / ( $thumbnail_dimensions['height'] / $thumbnail_dimensions['width'] ) );

			$initial_selection = array(
				round( ( $image[1] / 2 ) - ( $selected_width / 2 ) ), // Mid-point + half of height of selection.
				0,                                                    // Top edge (due to aspect ratio comparison).
				round( ( $image[1] / 2 ) + ( $selected_width / 2 ) ), // Mid-point - half of height of selection.
				$image[2],                                            // Bottom edge (due to aspect ratio comparison).
			);
		}

		?>

<div class="wrap">
	<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">

		<h2><?php echo esc_html( $title ); ?></h2>

		<noscript><p><strong style="color:red;font-size:20px;"><?php esc_html_e( 'Please enable Javascript to use this page.', 'wpcom-thumbnail-editor' ); ?></strong></p></noscript>

		<p><?php esc_html_e( 'The original image is shown in full below, although it may have been shrunk to fit on your screen. Please select the portion that you would like to use as the thumbnail image.', 'wpcom-thumbnail-editor' ); ?></p>

		<script type="text/javascript">
			jQuery(document).ready(function($){
				function update_preview ( img, selection ) {
					// This is how big the selection image is.
					var img_width  = <?php echo (int) $image[1]; ?>;
					var img_height = <?php echo (int) $image[2]; ?>;

					// This is how big the thumbnail preview needs to be.
					var thumb_width  = <?php echo (int) $thumbnail_dimensions['width']; ?>;
					var thumb_height = <?php echo (int) $thumbnail_dimensions['height']; ?>;

					var scaleX = thumb_width / ( selection.width || 1 );
					var scaleY = thumb_height / ( selection.height || 1 );

					// Update the preview image.
					$('#wpcom-thumbnail-edit-preview').css({
						width: Math.round( scaleX * img_width ) + 'px',
						height: Math.round( scaleY * img_height ) + 'px',
						marginLeft: '-' + Math.round( scaleX * selection.x1 ) + 'px',
						marginTop: '-' + Math.round( scaleY * selection.y1 ) + 'px'
					});
				}

				$('#wpcom-thumbnail-edit').imgAreaSelect({
					aspectRatio: '<?php echo intval( $thumbnail_dimensions['width'] ) . ':' . intval( $thumbnail_dimensions['height'] ); ?>',
					handles: true,

					// Initial selection.
					x1: <?php echo (int) $initial_selection[0]; ?>,
					y1: <?php echo (int) $initial_selection[1]; ?>,
					x2: <?php echo (int) $initial_selection[2]; ?>,
					y2: <?php echo (int) $initial_selection[3]; ?>,

					// Update the preview.
					onInit: function ( img, selection ) {
						update_preview( img, selection );
						$('#wpcom-thumbnail-edit-preview').show();
						$('#wpcom-thumbnail-edit').trigger('wpcom_thumbnail_edit_init');
					},
					onSelectChange: function ( img, selection ) {
						update_preview( img, selection );
						$('#wpcom-thumbnail-edit').trigger('wpcom_thumbnail_edit_change');
					},

					// Fill the hidden fields with the selected coordinates for the form.
					onSelectEnd: function ( img, selection ) {
						$('input[name="wpcom_thumbnail_edit_x1"]').val(selection.x1);
						$('input[name="wpcom_thumbnail_edit_y1"]').val(selection.y1);
						$('input[name="wpcom_thumbnail_edit_x2"]').val(selection.x2);
						$('input[name="wpcom_thumbnail_edit_y2"]').val(selection.y2);
						$('#wpcom-thumbnail-edit').trigger('wpcom_thumbnail_edit_selectend');
					}
				});
			});
		</script>

		<p><img src="<?php echo esc_url( $image[0] ); ?>" width="<?php echo (int) $image[1]; ?>" height="<?php echo (int) $image[2]; ?>" id="wpcom-thumbnail-edit" alt="
		<?php
			/* translators: 1: Image size */
			echo esc_attr( sprintf( __( '"%s" Thumbnail', 'wpcom-thumbnail-editor' ), $size ) );
		?>
		" /></p>

		<?php do_action( 'wpcom_thumbnail_editor_edit_thumbnail_screen', $attachment->ID, $size ); ?>

		<p>
			<?php submit_button( null, 'primary', 'submit', false ); ?>
			<?php submit_button( esc_html__( 'Reset Thumbnail', 'wpcom-thumbnail-editor' ), 'primary', 'wpcom_thumbnail_edit_reset', false ); ?>
			<a href="<?php echo esc_url( admin_url( 'media.php?action=edit&attachment_id=' . $attachment->ID ) ); ?>" class="button"><?php esc_html_e( 'Cancel Changes', 'wpcom-thumbnail-editor' ); ?></a>
		</p>

		<h3><?php esc_html_e( 'Fullsize Thumbnail Preview', 'wpcom-thumbnail-editor' ); ?></h3>

		<div style="overflow:hidden;width:<?php echo (int) $thumbnail_dimensions['width']; ?>px;height:<?php echo (int) $thumbnail_dimensions['height']; ?>px;">
			<img id="wpcom-thumbnail-edit-preview" class="hidden" src="<?php echo esc_url( wp_get_attachment_url( $attachment->ID ) ); ?>" />
		</div>

		<input type="hidden" name="action" value="wpcom_thumbnail_edit" />
		<input type="hidden" name="id" value="<?php echo (int) $attachment->ID; ?>" />
		<input type="hidden" name="size" value="<?php echo esc_attr( $size ); ?>" />
		<?php wp_nonce_field( 'wpcom_thumbnail_edit_' . $attachment->ID . '_' . $size ); ?>

		<!--
			Since the fullsize image is possibly scaled down, we need to record at what size it was
			displayed at so the we can scale up the new selection dimensions to the fullsize image.
		-->
		<input type="hidden" name="wpcom_thumbnail_edit_display_width"  value="<?php echo (int) $image[1]; ?>" />
		<input type="hidden" name="wpcom_thumbnail_edit_display_height" value="<?php echo (int) $image[2]; ?>" />

		<!-- These are manipulated via Javascript to submit the selected values -->
		<input type="hidden" name="wpcom_thumbnail_edit_x1" value="<?php echo (int) $initial_selection[0]; ?>" />
		<input type="hidden" name="wpcom_thumbnail_edit_y1" value="<?php echo (int) $initial_selection[1]; ?>" />
		<input type="hidden" name="wpcom_thumbnail_edit_x2" value="<?php echo (int) $initial_selection[2]; ?>" />
		<input type="hidden" name="wpcom_thumbnail_edit_y2" value="<?php echo (int) $initial_selection[3]; ?>" />
	</form>
</div>

		<?php

		require ABSPATH . '/wp-admin/admin-footer.php';
	}

	/**
	 * Processes the submission of the thumbnail crop selection screen and saves the results to post meta.
	 */
	public function post_handler() {

		// Validate "id" and "size" POST values and check user capabilities. Dies on error.
		$attachment = $this->validate_parameters();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		$size = $_REQUEST['size'];

		check_admin_referer( 'wpcom_thumbnail_edit_' . $attachment->ID . '_' . $size );

		// Reset to default?
		if ( ! empty( $_POST['wpcom_thumbnail_edit_reset'] ) ) {
			$this->delete_coordinates( $attachment->ID, $size );

			wp_safe_redirect( admin_url( 'media.php?action=edit&attachment_id=' . $attachment->ID . '&wtereset=1' ) );
			exit();
		}

		$required_fields = array(
			'wpcom_thumbnail_edit_display_width'  => 'display_width',
			'wpcom_thumbnail_edit_display_height' => 'display_height',
			'wpcom_thumbnail_edit_x1'             => 'selection_x1',
			'wpcom_thumbnail_edit_y1'             => 'selection_y1',
			'wpcom_thumbnail_edit_x2'             => 'selection_x2',
			'wpcom_thumbnail_edit_y2'             => 'selection_y2',
		);

		foreach ( $required_fields as $required_field => $variable_name ) {
			if ( empty( $_POST[ $required_field ] ) && 0 != $_POST[ $required_field ] ) {
				/* translators: 1: parameter markup */
				wp_die( sprintf( esc_html__( 'Invalid %s parameter.', 'wpcom-thumbnail-editor' ), '<code>' . esc_html( $required_field ) . '</code>' ) );
			}

			$$variable_name = (int) $_POST[ $required_field ];
		}

		$attachment_metadata = wp_get_attachment_metadata( $attachment->ID );

		$selection_coordinates = array( 'selection_x1', 'selection_y1', 'selection_x2', 'selection_y2' );

		// If the image was scaled down on the selection screen,
		// then we need to scale up the selection to fit the fullsize image.
		if ( $attachment_metadata['width'] > $display_width || $attachment_metadata['height'] > $display_height ) {
			$scale_ratio = $attachment_metadata['width'] / $display_width;

			foreach ( $selection_coordinates as $selection_coordinate ) {
				${'fullsize_' . $selection_coordinate} = round( $$selection_coordinate * $scale_ratio );
			}
		} else {
			// Remap.
			foreach ( $selection_coordinates as $selection_coordinate ) {
				${'fullsize_' . $selection_coordinate} = $$selection_coordinate;
			}
		}

		// Save the coordinates.
		$this->save_coordinates( $attachment->ID, $size, array( $fullsize_selection_x1, $fullsize_selection_y1, $fullsize_selection_x2, $fullsize_selection_y2 ) );

		// Allow for saving custom fields.
		do_action( 'wpcom_thumbnail_editor_post_handler', $attachment->ID, $size );

		wp_safe_redirect( admin_url( 'media.php?action=edit&attachment_id=' . $attachment->ID . '&wteupdated=1' ) );
		exit();
	}

	/**
	 * Makes sure that the "id" (attachment ID) and "size" (thumbnail size) query string parameters are valid
	 * and dies if they are not. Returns attachment object with matching ID on success.
	 *
	 * @return null|object Dies on error, returns attachment object on success.
	 */
	public function validate_parameters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended

		if ( empty( $_REQUEST['id'] ) ) {
			/* translators: 1: parameter markup */
			wp_die( sprintf( esc_html__( 'Invalid %s parameter.', 'wpcom-thumbnail-editor' ), '<code>id</code>' ) );
		}

		$attachment = get_post( intval( $_REQUEST['id'] ) );
		if ( ! $attachment ) {
			/* translators: 1: parameter markup */
			wp_die( sprintf( esc_html__( 'Invalid %s parameter.', 'wpcom-thumbnail-editor' ), '<code>id</code>' ) );
		}

		if ( 'attachment' != $attachment->post_type || ! wp_attachment_is_image( $attachment->ID ) ) {
			wp_die( esc_html__( 'That is not a valid image attachment.', 'wpcom-thumbnail-editor' ) );
		}

		if ( ! current_user_can( get_post_type_object( $attachment->post_type )->cap->edit_post, $attachment->ID ) ) {
			wp_die( esc_html__( 'You are not allowed to edit this attachment.', 'wpcom-thumbnail-editor' ) );
		}

		if ( $this->use_ratio_map ) {
			if ( empty( $_REQUEST['size'] ) || ! in_array( $_REQUEST['size'], $this->get_image_sizes_by_ratio(), true ) ) {
				/* translators: 1: parameter markup */
				wp_die( sprintf( esc_html__( 'Invalid %s parameter.', 'wpcom-thumbnail-editor' ), '<code>size</code>' ) );
			}
		} else {
			if ( empty( $_REQUEST['size'] ) || ! in_array( $_REQUEST['size'], $this->get_intermediate_image_sizes(), true ) ) {
				/* translators: 1: parameter markup */
				wp_die( sprintf( esc_html__( 'Invalid %s parameter.', 'wpcom-thumbnail-editor' ), '<code>size</code>' ) );
			}
		}

		// phpcs:enable

		return $attachment;
	}

	/**
	 * Returns all thumbnail size names. get_intermediate_image_sizes() is filtered to return an
	 * empty array on WordPress.com so this function removes that filter, calls the function,
	 * and then re-adds the filter back onto the function.
	 *
	 * @param bool $cropped_only Return only cropped images.
	 * @return array An array of image size strings.
	 */
	public function get_intermediate_image_sizes( $cropped_only = true ) {
		global $_wp_additional_image_sizes;

		// /wp-content/mu-plugins/wpcom-media.php.
		$had_filter = remove_filter( 'intermediate_image_sizes', 'wpcom_intermediate_sizes' );

		$sizes = get_intermediate_image_sizes(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_intermediate_image_sizes_get_intermediate_image_sizes

		if ( $had_filter ) {
			add_filter( 'intermediate_image_sizes', 'wpcom_intermediate_sizes' );
		}

		if ( apply_filters( 'wpcom_thumbnail_editor_cropped_only', $cropped_only ) ) {
			$filtered_sizes = array();

			foreach ( $sizes as $size ) {
				switch ( $size ) {
					case 'thumbnail':
						if ( get_option( 'thumbnail_crop' ) ) {
							$filtered_sizes[] = $size;
						}
						break;

					case 'medium':
					case 'large':
						break;

					default:
						if ( ! empty( $_wp_additional_image_sizes[ $size ] ) && $_wp_additional_image_sizes[ $size ]['crop'] ) {
							$filtered_sizes[] = $size;
						}
				}
			}

			$sizes = $filtered_sizes;
		}

		return apply_filters( 'wpcom_thumbnail_editor_get_intermediate_image_sizes', $sizes, $cropped_only );
	}

	/**
	 * Gets the first size defined for each dimension. All images are assumed to be cropped
	 *
	 * @todo Add validation that image sizes are of the cropped variety?
	 * @return array Array of image size strings.
	 */
	public function get_image_sizes_by_ratio() {
		$ratios = array_keys( $this->image_ratio_map );

		foreach ( $ratios as $ratio ) {
			if ( isset( $this->image_ratio_map[ $ratio ][0] ) ) {
				$sizes[ $ratio ] = $this->image_ratio_map[ $ratio ][0];
			}
		}

		return $sizes;
	}

	/**
	 * Returns the width and height of a given thumbnail size.
	 *
	 * @param string $size Thumbnail size name.
	 * @return array|false Associative array of width and height in pixels. False on invalid size.
	 */
	public function get_thumbnail_dimensions( $size ) {
		global $_wp_additional_image_sizes;

		switch ( $size ) {
			case 'thumbnail':
			case 'medium':
			case 'large':
				$width  = get_option( $size . '_size_w' );
				$height = get_option( $size . '_size_h' );
				break;

			default:
				if ( empty( $_wp_additional_image_sizes[ $size ] ) ) {
					return false;
				}

				$width  = $_wp_additional_image_sizes[ $size ]['width'];
				$height = $_wp_additional_image_sizes[ $size ]['height'];
		}

		// Just to be safe.
		$width  = (int) $width;
		$height = (int) $height;

		return array(
			'width'  => $width,
			'height' => $height,
		);
	}

	/**
	 * Fetches the coordinates for a custom crop for a given attachment ID and thumbnail size.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $size Thumbnail size name.
	 * @return array|false Array of crop coordinates or false if no custom selection set.
	 */
	public function get_coordinates( $attachment_id, $size ) {
		$sizes = (array) get_post_meta( $attachment_id, $this->post_meta, true );

		$coordinates = false;

		if ( empty( $sizes[ $size ] ) ) {
			// Coordinates not explictly set for this size, but is it in a size group? If so, we can use the coordinates
			// from other sizes in the same group, as they are always the same. Happens if a size is added to a group later and hasn't
			// been backfilled in all post meta. Not sure why coords are saved for every size, rather than group, but hey.
			if ( $this->use_ratio_map ) {
				foreach ( $this->image_ratio_map as $ratio => $ratio_sizes ) {
					foreach ( $ratio_sizes as $ratio_size ) {
						if ( $size === $ratio_size ) {
							// Determine if there are any saved coordinates that match the desired $size in the matched ratio group.
							$intersect = array_intersect_key( $ratio_sizes, $sizes );

							if ( is_array( $intersect ) && ! empty( $intersect ) ) {
								foreach ( $intersect as $matching_size ) {
									if ( isset( $sizes[ $matching_size ] ) ) {
										$coordinates = $sizes[ $matching_size ];

										break;
									}
								}
							}
						}
					}
				}
			}
		} else {
			$coordinates = $sizes[ $size ];
		}

		return $coordinates;
	}

	/**
	 * Saves the coordinates for a custom crop for a given attachment ID and thumbnail size.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $size          Thumbnail size name.
	 * @param array  $coordinates   Array of coordinates in the format `array( x1, y1, x2, y2 )`.
	 */
	public function save_coordinates( $attachment_id, $size, $coordinates ) {
		$sizes = (array) get_post_meta( $attachment_id, $this->post_meta, true );

		$sizes[ $size ] = $coordinates;

		// save meta for all the related sizes to if were using a ratio map.
		if ( $this->use_ratio_map ) {
			$related_sizes = $this->get_related_sizes( $size );

			// add the same meta value to the related sizes.
			if ( count( $related_sizes ) ) {
				foreach ( $related_sizes as $related_size ) {
					$sizes[ $related_size ] = $coordinates;
				}
			}
		}

		update_post_meta( $attachment_id, $this->post_meta, $sizes );
	}

	/**
	 * Find the siblings of the passed size so we can apply the coordinates to them too.
	 *
	 * @param string $size Image size.
	 * @return array Array of related image size strings
	 */
	public function get_related_sizes( $size ) {
		$related_sizes = array();

		// find out which ratio map the size belongs to.
		foreach ( $this->image_ratio_map as $ratio => $ratio_sizes ) {
			foreach ( $ratio_sizes as $ratio_size ) {
				if ( $ratio_size == $size ) {
					$related_sizes = $this->image_ratio_map[ $ratio ];
					break 2;
				}
			}
		}

		return $related_sizes;
	}

	/**
	 * Deletes the coordinates for a custom crop for a given attachment ID and thumbnail size.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $size Thumbnail size name.
	 * @return bool False on failure (probably no such custom crop), true on success.
	 */
	public function delete_coordinates( $attachment_id, $size ) {
		$sizes = get_post_meta( $attachment_id, $this->post_meta, true );
		if ( ! $sizes ) {
			return false;
		}

		if ( empty( $sizes[ $size ] ) ) {
			return false;
		}

		unset( $sizes[ $size ] );

		// also unset related sizes.
		if ( $this->use_ratio_map ) {
			$related_sizes = $this->get_related_sizes( $size );
			if ( count( $related_sizes ) ) {
				foreach ( $related_sizes as $related_size ) {
					unset( $sizes[ $related_size ] );
				}
			}
		}

		return update_post_meta( $attachment_id, $this->post_meta, $sizes );
	}

	/**
	 * Returns the attributes for a given attachment thumbnail. Meant for hooking into image_downsize().
	 *
	 * @param array|false $existing_resize Any existing data. Returned on no action.
	 * @param int         $attachment_id Attachment ID.
	 * @param string      $size Thumbnail size name.
	 * @return mixed Array of thumbnail details (URL, width, height, is_intermedite) or the previous data.
	 */
	public function get_thumbnail_url( $existing_resize, $attachment_id, $size ) {
		if ( ! $this->photon_is_available() ) {
			return $existing_resize;
		}

		// Named sizes only.
		if ( is_array( $size ) ) {
			return $existing_resize;
		}

		$coordinates = $this->get_coordinates( $attachment_id, $size );

		if ( ! $coordinates || ! is_array( $coordinates ) || 4 != count( $coordinates ) ) {
			return $existing_resize;
		}

		$thumbnail_size = $this->get_thumbnail_dimensions( $size );
		if ( ! $thumbnail_size ) {
			return $existing_resize;
		}

		list( $selection_x1, $selection_y1, $selection_x2, $selection_y2 ) = $coordinates;

		if ( function_exists( 'jetpack_photon_url' ) ) {
			$url = jetpack_photon_url(
				wp_get_attachment_url( $attachment_id ),
				apply_filters(
					'wpcom_thumbnail_editor_thumbnail_args',
					array(
						'crop'   => array(
							$selection_x1 . 'px',
							$selection_y1 . 'px',
							( $selection_x2 - $selection_x1 ) . 'px',
							( $selection_y2 - $selection_y1 ) . 'px',
						),
						'resize' => array(
							$thumbnail_size['width'],
							$thumbnail_size['height'],
						),
					),
					$attachment_id,
					$size,
					$thumbnail_size
				)
			);
		} else {
			$url = wp_get_attachment_url( $attachment_id );
		}

		return array( $url, $thumbnail_size['width'], $thumbnail_size['height'], true );
	}

	/**
	 * Maintain in `srcset` URLs any custom crops set by the WPCom Thumbnail
	 * Editor.
	 *
	 * @param array  $sources `srcset` source data.
	 * @param array  $size    Maximum dimensions requested for `srcset` images.
	 * @param string $src     `src` of original image.
	 * @return array
	 */
	public function maintain_thumbnail_crop_in_srcset(
		$sources,
		$size,
		$src
	) {
		if ( empty( $sources ) ) {
			return $sources;
		}

		if ( ! $this->photon_is_available() ) {
			return $sources;
		}

		$full_size_params_string = wp_parse_url( $src, PHP_URL_QUERY );

		if ( ! is_string( $full_size_params_string ) ) {
			return $sources;
		}

		$full_size_params = [];
		wp_parse_str( $full_size_params_string, $full_size_params );
		unset( $full_size_params_string );

		if ( ! isset( $full_size_params['crop'] ) ) {
			return $sources;
		}

		$full_size_src = explode( '?', $src, -1 )[0];

		foreach ( $sources as $key => $source ) {
			/**
			 * We cannot mix `x` and `w` descriptors, so if another callback
			 * adds the `x` descriptor (used to indicate pixel density), we must
			 * bail.
			 */
			if ( 'x' === $source['descriptor'] ) {
				return $sources;
			}

			$source_params_string = wp_parse_url(
				$source['url'],
				PHP_URL_QUERY
			);

			if ( ! is_string( $source_params_string ) ) {
				$source_params_string = '';
			}

			$source_params = [];
			wp_parse_str( $source_params_string, $source_params );
			unset( $source_params_string );

			if ( isset( $source_params['crop'] ) ) {
				continue;
			}

			$merged_params = array_replace(
				$full_size_params,
				$source_params
			);
			unset( $source_params );

			/**
			 * Cannot apply both `fit` and `resize` transforms, and `fit` will
			 * change the crop, so drop it if both are present.
			 */
			if ( isset( $merged_params['fit'], $merged_params['resize'] ) ) {
				unset( $merged_params['fit'] );
			}

			/**
			 * Ensure resized image matches expected width. Photon supports
			 * chaining both `resize` and `w`, and applies each transform in the
			 * order that it is passed in the arguments array. `fit`, however,
			 * is incompatible with `w`.
			 */
			if ( isset( $merged_params['fit'] ) ) {
				unset( $merged_params['w'] );
			} elseif (
				isset( $merged_params['resize'] )
				&& 0 !== strpos(
					$merged_params['resize'],
					$source['value'] . ','
				)
			) {
				// `w` is ignored if it appears before `resize`.
				unset( $merged_params['w'] );

				$merged_params['w'] = $source['value'];
			}

			$source['url']   = jetpack_photon_url(
				$full_size_src,
				$merged_params
			);
			$sources[ $key ] = $source;
		}

		return $sources;
	}

	/**
	 * Determine if Photon is available and able to resize images.
	 *
	 * On dev sites, Jetpack is often active but Photon will not work because
	 * the content files are not accessible to the public internet.
	 *
	 * Right now, a broken image is displayed when this plugin is active and a
	 * thumbnail has been edited. This will allow the unmodified image to be
	 * displayed.
	 *
	 * @return bool
	 */
	protected function photon_is_available() {
		$available = ! function_exists( 'jetpack_photon_url' )
			|| (
				true === defined( 'JETPACK_DEV_DEBUG' )
				&& true === constant( 'JETPACK_DEV_DEBUG' )
			);

		return apply_filters(
			'wpcom_thumbnail_editor_photon_is_available',
			$available
		);
	}
}

// initializing the class on init so we can filter the args.
add_action(
	'init',
	function() {
		$GLOBALS['WPcom_Thumbnail_Editor'] = new WPcom_Thumbnail_Editor();
	}
);
