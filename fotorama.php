.<?php
/*
Plugin Name: Fotorama
Plugin URI: http://fotoramajs.com
Description: Transforms standard gallery into Fotorama. Free to test and develop. <strong><a href="http://fotoramajs.com/license/">Get a license for web use</a>.</strong>
Version: 0.4
Author: Artem Polikarpov & Anna Shishlyakova
License: http://fotoramajs.com/license/
 */

define('FOTORAMA_SETTINGS_FILE', WP_PLUGIN_DIR.'/'.dirname(plugin_basename(__FILE__)).'/fotoramaDefaults.js');
define('FOTORAMA_MAX_FULLSIZE_WIDTH', 1280);
define('FOTORAMA_MAX_FULLSIZE_HEIGHT', 1280);
define('FOTORAMA_REQUIRED_JQUERY_VERSION', '1.4.4');

add_action('init', 'fotorama_scripts');
add_action('wp_head', 'fotorama_replace_default_shortcode');
add_action('wp_print_scripts', 'fotorama_defaults');

// Admin
add_action('admin_init', 'fotorama_load_textdomain');
add_action('admin_head', 'fotorama_tweak_default_gallery_settings');
add_action('admin_menu', 'fotorama_settings_menu_item');

/**
 * Replaces default shortcode with that of Fotorama
 * @return void
 */
function fotorama_replace_default_shortcode() {
	remove_shortcode('gallery');
	add_shortcode('gallery', 'fotorama_shortcode');
}

/**
 * Expands Fotorama shortcode
 * @param  $gallery_settings Settings for default WordPress gallery
 * @return void
 */
function fotorama_shortcode($gallery_settings) {
	global $post;

	$settings = get_option('fotorama');

	$size = $settings['imageSize'] ? $settings['imageSize'] : 'large';
	$settings_width = $settings['width'];
	$settings_height = $settings['height'];
	$orderby = isset($gallery_settings['orderby']) ? $gallery_settings['orderby'] : 'menu_order';
	$orderby = sanitize_sql_orderby($orderby);
	$order = isset($gallery_settings['order']) && $gallery_settings['order'] == 'DESC' ? 'DESC' : 'ASC';

	$include = isset($gallery_settings['include']) ? $gallery_settings['include'] : '';
	$exclude = isset($gallery_settings['exclude']) ? $gallery_settings['exclude'] : '';

	if (!empty($include)) {
		$include = preg_replace('/[^0-9,]+/', '', $include);
		$_attachments = get_posts(array('include' => $include,
		                               'post_status' => 'inherit',
		                               'post_type' => 'attachment',
		                               'post_mime_type' => 'image',
		                               'order' => $order,
		                               'orderby' => $orderby));

		$attachments = array();
		foreach ($_attachments as $key => $val) {
			$attachments[$val->ID] = $_attachments[$key];
		}
	} elseif (!empty($exclude)) {
		$exclude = preg_replace('/[^0-9,]+/', '', $exclude);
	$attachments = get_children(array('post_parent' => $post->ID,
		                                 'exclude' => $exclude,
		                                 'post_status' => 'inherit',
	                                 'post_type' => 'attachment',
	                                 'post_mime_type' => 'image',
	                                 'order' => $order,
		                                 'orderby' => $orderby));
	} else {
		$attachments = get_children(array('post_parent' => $post->ID,
												'post_status' => 'inherit',
	                                 'post_type' => 'attachment',
	                                 'post_mime_type' => 'image',
	                                 'order' => $order,
	                                 'orderby' => $orderby));
	}

	if (empty($attachments)) {
		return '';
	}

	$first_image_width = null;
	$first_image_height = null;
	$images_html = array();
	foreach ($attachments as $id => $attachment) {
		// Rettrieve image of the selected size (medium or large)
		$image = wp_get_attachment_image_src($id, $size);
		$src = $image[0];
		$width = $image[1];
		$height = $image[2];
		// Retrieve thumbnail
		$thumbnail = wp_get_attachment_image_src($id, 'thumbnail');
		$thumbnail_src = $thumbnail[0];
		// Make sure fullsize image does not exceed maximum allowed width and height
		$fullsize_src = $settings['fullscreenIcon'] ? fotorama_get_fullsize_image_url($id, $attachment->guid) : '';
		// Store the width and height of the first image
		$first_image_width = $first_image_width ? $first_image_width : $width;
		$first_image_height = $first_image_height ? $first_image_height : $height;
		$images_html[] = "\n\t\t\t".'<a href="'.$src.'" rel="'.$fullsize_src.'"><img src="'.$thumbnail_src.'" alt="'.htmlspecialchars($attachment->post_title).'" /></a>';
	}
	$output = '<div class="fotorama" id="fotorama-'.$post->ID.'" style="width: '.($settings_width ? $settings_width : $first_image_width).'px;" data-width="100%" data-height="auto" data-aspectRatio="'.($settings_width ? $settings_width : $first_image_width) / ($settings_height ? $settings_height : $first_image_height).'">'.implode('', $images_html);
	$output .= "\n\t\t".'</div>';
	return $output;
}

/**
 * Retrieves fullsize image, making sure
 * @param integer $id Attachment id
 * @param string $src Attachment src
 * @return string Image url
 */
function fotorama_get_fullsize_image_url($id, $src) {
	$meta = get_post_meta($id, '_wp_attachment_metadata', true);
	$original_width = $meta['width'];
	$original_height = $meta['height'];
	// If width or height of the original exceeds maximum allowed dimensions, generate a new image of the right size
	if ($original_width > FOTORAMA_MAX_FULLSIZE_WIDTH || $original_height > FOTORAMA_MAX_FULLSIZE_HEIGHT) {
		$original_path = WP_CONTENT_DIR.'/uploads/'.get_post_meta($id, '_wp_attached_file', true);
		// If original file is not there (could have been removed manually or whatever), do nothing.
		if (!file_exists($original_path)) {
			return null;
		}
		$new_dimensions = wp_constrain_dimensions($original_width, $original_height, FOTORAMA_MAX_FULLSIZE_WIDTH, FOTORAMA_MAX_FULLSIZE_HEIGHT);
		$suffix = "{$new_dimensions[0]}x{$new_dimensions[1]}";
		// Check whether generated image already exists
		$new_path = fotorama_make_new_file_name($original_path, $suffix);
		if (!file_exists($new_path)) {
			$new_path = image_resize($original_path, FOTORAMA_MAX_FULLSIZE_WIDTH, FOTORAMA_MAX_FULLSIZE_HEIGHT, false, $suffix);
		}
		// If an error happened during image resize, do nothing.
		if ($new_path instanceof WP_Error) {
			return null;
		}
		$src = str_replace(WP_CONTENT_DIR, WP_CONTENT_URL, $new_path);
	}
	return $src;
}

function fotorama_make_new_file_name($original_path, $suffix) {
	$info = pathinfo($original_path);
	$dir = $info['dirname'];
	$extension = $info['extension'];
	$file_name = wp_basename($original_path, ".$extension");
	return "{$dir}/{$file_name}-{$suffix}.{$extension}";
}

/**
 * Loads Fotorama scripts and stylesheet
 * @return void
 */
function fotorama_scripts() {
	// Make sure that jQuery version is 1.4.4 or newer
	global $wp_scripts;
	// Register scripts and styles in non-admin area only.
	if (!is_admin()) {
			// If built-in jQuery version is below jQuery version required by Fotorama, replace built-in jQuery with Fotorama's
		if (version_compare($wp_scripts->registered['jquery']->ver, FOTORAMA_REQUIRED_JQUERY_VERSION) == -1) {
			wp_deregister_script('jquery');
			wp_register_script('jquery', WP_PLUGIN_URL.'/'.dirname(plugin_basename(__FILE__)).'/jquery-1.7.2.min.js');
		}

		// Scripts
		wp_register_script('fotorama.js', WP_PLUGIN_URL.'/'.dirname(plugin_basename(__FILE__)).'/fotorama.js', array('jquery'));
		wp_enqueue_script('fotorama.js');
		// Not using fotoramaDefaults.js file for now.
		//		wp_register_script('fotoramaDefaults.js', WP_PLUGIN_URL.'/'.dirname(plugin_basename(__FILE__)).'/fotoramaDefaults.js', array('jquery'));
		//		wp_enqueue_script('fotoramaDefaults.js');

		// Stylesheets
		wp_register_style('fotorama.css', WP_PLUGIN_URL.'/'.dirname(plugin_basename(__FILE__)).'/fotorama.css');
		wp_enqueue_style('fotorama.css');
	}
}

/**
 * Outputs default settings as JavaScript object
 * @return void
 */
function fotorama_defaults() {
	require_once(ABSPATH.'/wp-includes/class-json.php');
	$json_encoder = new Services_JSON(SERVICES_JSON_LOOSE_TYPE);
	$settings = get_option('fotorama');
	$settings = is_array($settings) ? $settings : array();
	$settings = array_merge(fotorama_get_default_settings(), $settings);
	$template = "fotoramaDefaults = {
			width: {width},
			height: {height},

			touchStyle: {touchStyle},
			autoplay: {autoplay},

			fullscreenIcon: {fullscreenIcon},

			arrows: {arrows},

			nav: {nav},

			caption: {caption}
		};\n";
	foreach ($settings as $key => $value) {
		$template = str_replace('{'.$key.'}', method_exists($json_encoder, '_encode') ? $json_encoder->_encode($value) : $json_encoder->encode($value), $template);
	}
	?>
	<style>
		.fotorama { margin-bottom: 2em; max-width: 100%; }
	</style>
	<script type="text/javascript">
		<?php echo $template; ?>
	</script>
	<?php
}

/**
 * Loads language file
 * @return void
 */
function fotorama_load_textdomain() {
	// Looking for a file with a name fotorama-ru_RU.mo
	load_plugin_textdomain('fotorama', false, dirname(plugin_basename(__FILE__)));
}

function fotorama_tweak_default_gallery_settings() {
	?>
		<script type="text/javascript">
			try {
				jQuery(function() {
					jQuery('#linkto-file').parents('tr:first').hide();
					jQuery('#columns').parents('tr:first').hide();
				});
			}
			catch(e) {}
		</script>
	<?php
}

/**
 * Adds settings page to settings menu.
 * @return void
 */
function fotorama_settings_menu_item() {
	add_options_page(__('Fotorama Settings', 'fotorama'),
	                 '<img class="menu_pto" height="11" width="10" style="top: 2px; position: relative;" alt="" src="'.WP_PLUGIN_URL.'/'.dirname(plugin_basename(__FILE__)).'/images/fotorama-icon_10.png"> '.__('Fotorama'),
	                 'manage_options', 'fotorama_settings', 'fotorama_settings_page');
}

/**
 * Displays settings page.
 * @return void
 */
function fotorama_settings_page() {
	// Reading settings from fotoramaDefaults.js file
	// Reading / writing to file rarely works because of file permissions.
	$settings = fotorama_get_default_settings();

	// Getting additional settings from WordPress options
	$additional_settings = get_option('fotorama');
	$additional_settings = $additional_settings ? $additional_settings : array('imageSize' => 'large');

	// Merging additional settings with settings from fotoramaDefaults.js file
	$settings = array_merge($settings, $additional_settings);

	// Normalizing settings
	$settings['autoplayPauseDuration'] = $settings['autoplay'] ? $settings['autoplay'] / 1000 : null;
	$settings['caption'] = $settings['caption'] ? $settings['caption'] : 'none';
	$settings['nav'] = $settings['nav'] ? $settings['nav'] : 'none';

	$updated = false;
	if (isset($_POST['fotorama'])) {
		$updated = true;

		$submitted_settings = $_POST['fotorama'];
		// Normalizing settings
		$submitted_settings['arrows'] = isset($submitted_settings['arrows']) ? $submitted_settings['arrows'] : false;
		$submitted_settings['fullscreenIcon'] = isset($submitted_settings['fullscreenIcon']) ? $submitted_settings['fullscreenIcon'] : false;

		// Merging submitted settings (if any) with settings from fotoramaDefaults.js file.
		$settings = array_merge($settings, $submitted_settings);

		// Normalizing settings
		$settings['touchStyle'] = $settings['touchStyle'] == 1;
		$settings['arrows'] = $settings['arrows'] == 1;
		$settings['autoplayPauseDuration'] = $settings['autoplayPauseDuration'] ? intval($settings['autoplayPauseDuration']) : 5;
		$settings['autoplay'] = $settings['autoplay'] == 1 ? intval($settings['autoplayPauseDuration']) * 1000 : false;
		$settings['fullscreenIcon'] = $settings['fullscreenIcon'] == 1;
		$settings['width'] = $settings['width'] ? intval($settings['width']) : null;
		$settings['height'] = $settings['height'] ? intval($settings['height']) : null;

		// Writing settings back to file
		// Writing to file is impossible more often than not, disabling it for now.
		/*fotorama_write_settings_to_file($settings);*/

		// Updating WordPress options
		$additional_settings['imageSize'] = $settings['imageSize'];
		$additional_settings['width'] = $settings['width'];
		$additional_settings['height'] = $settings['height'];

		update_option('fotorama', $settings);
	}

	?>
	<div class="wrap">
		<?php
		if ($updated) :
			?>
			<div class="updated" id="message">
				<p>
					<?php _e('Settings updated', 'fotorama'); ?>
				</p>
			</div>
			<?php
  		endif;
		?>
		<div class="icon32" style="background: url(<?php echo WP_PLUGIN_URL.'/'.dirname(plugin_basename(__FILE__)); ?>/images/fotorama-icon_32.png) no-repeat 2px 3px;"></div>
		<h2><?php _e('Fotorama Settings', 'fotorama'); ?></h2>

		<form method="post" action="" id="fotorama-options">
			<?php settings_fields('fotorama_settings'); ?>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><?php _e('Behavior', 'fotorama'); ?></th>
					<td>

						<nobr><label>
							<input type="radio" name="fotorama[touchStyle]" value="1" id="fo__touchStyle" <?php echo $settings['touchStyle'] ? 'checked' : ''; ?>>
							<?php _e('Drag & swipe images', 'fotorama'); ?></span>
						</label></nobr>

						<br>
						<nobr><label>
							<input type="radio" name="fotorama[touchStyle]" value="0" id="fo__touchStyle_false" <?php echo !$settings['touchStyle'] ? 'checked' : ''; ?>>
							<?php _e('Click only', 'fotorama'); ?></span>
						</label></nobr>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row"><?php _e('Navigation', 'fotorama'); ?></th>
					<td>
						<nobr><label>
							<input type="radio" name="fotorama[nav]" value="thumbs" id="fo__nav_thumbs" <?php echo $settings['nav'] == 'thumbs' ? 'checked' : ''; ?>>
							<?php _e('Image thumbnails', 'fotorama'); ?>
						</label></nobr>

						<br>
						<nobr><label>
							<input type="radio" name="fotorama[nav]" value="dots" id="fo__nav_dots" <?php echo $settings['nav'] == 'dots' ? 'checked' : ''; ?>>
							<?php _e('iPhone-style dots', 'fotorama'); ?>
						</label></nobr>

						<br>
						<nobr><label>
							<input type="radio" name="fotorama[nav]" value="none" id="fo__nav_none" <?php echo $settings['nav'] == 'none' ? 'checked' : ''; ?>>
							<?php _e('Nothing', 'fotorama'); ?>
						</label></nobr>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row"></th>
					<td>
						<nobr><label>
							<input type="checkbox" name="fotorama[arrows]" value="1" id="fo__arrows" <?php echo $settings['arrows'] ? 'checked' : ''; ?>>
							<?php _e('Draw arrows', 'fotorama'); ?>
						</label></nobr>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row"><?php _e('Captions', 'fotorama'); ?></th>
					<td>
						<label>
							<input type="radio" name="fotorama[caption]" value="simple" id="fo__captions" <?php echo $settings['caption'] == 'simple' ? 'checked' : ''; ?> class="fo__toggling-input">
							<?php _e('Under fotorama', 'fotorama'); ?>
						</label>

						<br>
						<label>
							<input type="radio" name="fotorama[caption]" value="overlay" id="fo__captionsOnPicture" <?php echo $settings['caption'] == 'overlay' ? 'checked' : ''; ?>>
							<?php _e('On picture', 'fotorama'); ?>
						</label>

						<br>
						<label>
							<input type="radio" name="fotorama[caption]" value="none" id="fo__captionsNone" <?php echo $settings['caption'] == 'none' ? 'checked' : ''; ?>>
							<?php _e('None', 'fotorama'); ?>
						</label>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row"><?php _e('Autoplay', 'fotorama'); ?></th>
					<td>
						<nobr><label>
							<input type="checkbox" name="fotorama[autoplay]" value="1" id="fo__autoplay-checkbox" class="fo__toggling-input" <?php echo $settings['autoplay'] ? 'checked' : ''; ?> >
							<?php _e('Play all fotoramas as slideshows on page load', 'fotorama'); ?>
						</label></nobr>

						<br>
						<nobr><label>
							<?php _e('Interval', 'fotorama'); ?> <input type="number" min="0" style="width: 60px;" id="fo__autoplay" name="fotorama[autoplayPauseDuration]" class="small-text" value="<?php echo ($settings['autoplayPauseDuration'] ? $settings['autoplayPauseDuration'] : 5); ?>" <?php echo $settings['autoplay'] ? '' : 'disabled'; ?>><?php _e('sec', 'fotorama'); ?>
						</label></nobr>
						<br>
						<span class="description"><?php _e('Stops at any user action with fotorama.', 'fotorama'); ?></span>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row"><?php _e('Size', 'fotorama'); ?></th>
					<td>
						<nobr><label>
							<?php _e('Width', 'fotorama'); ?>
							<input type="number" min="0" style="width: 60px;" id="fo__width" name="fotorama[width]" value="<?php echo $settings['width']; ?>" class="small-text">px
						</label></nobr>
						&nbsp;&nbsp;&nbsp;
						<nobr><label>
							<?php _e('Height', 'fotorama'); ?>
							<input type="number" min="0" style="width: 60px;" id="fo__height" name="fotorama[height]" value="<?php echo $settings['height']; ?>" class="small-text">px
						</label></nobr>

						<br>
						<span class="description"><?php _e('By default, fotorama&rsquo;s dimensions are the dimensions of the first image.', 'fotorama'); ?></span>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"></th>
					<td>
						<nobr><label>
							<?php _e('Image size', 'fotorama'); ?>
							<select name="fotorama[imageSize]" id="fo__image-size">
								<option value="medium" <?php echo $settings['imageSize'] == 'medium' ? 'selected' : ''; ?>><?php _e('Medium', 'fotorama'); ?></option>
								<option value="large" <?php echo $settings['imageSize'] == 'large' ? 'selected' : ''; ?>><?php _e('Large', 'fotorama'); ?></option>
							</select>
						</label></nobr>

						<br>
						<span class="description"><?php _e('Size of the image you want Fotorama to use. These sizes are set in <a href="options-media.php">Media Settings</a>.', 'fotorama'); ?></span>
					</td>
				</tr>

				<tr valign="top">
					<th scope="row"></th>
					<td>
						<nobr><label>
							<input type="checkbox" name="fotorama[fullscreenIcon]" value="1" id="fo__fullscreenIcon" <?php echo $settings['fullscreenIcon'] ? 'checked' : ''; ?>>
							<?php _e('Allow fullscreen', 'fotorama'); ?>
						</label></nobr>
					</td>
				</tr>
			</table>
			<p class="submit"><input type="submit" name="submit" id="submit" class="button-primary" value="<?php _e('Save Changes', 'fotorama'); ?>"></p>
		</form>
	</div>
	<script type="text/javascript">
		(function() {
			var autoplayCheckbox = document.getElementById('fo__autoplay-checkbox');
			autoplayCheckbox.onclick = function() {
				var autoplayNumber = document.getElementById('fo__autoplay');
				if (this.checked) {
					autoplayNumber.removeAttribute('disabled');
				}
				else {
					autoplayNumber.setAttribute('disabled', true);
				}
			}
		})();
	</script>
	<?php
}

/**
 * Retrieves default settings
 * @return array
 */
function fotorama_get_default_settings() {
	/*$settings = fotorama_read_settings_from_file();
	return is_array($settings) ? $settings : array();*/
	return array(
		'touchStyle' => true,
		'nav' => 'dots',
		'arrows' => true,
		'caption' => 'none',
		'autoplay' => false,
		'width' => null,
		'height' => null,
		'fullscreenIcon' => false,
		'autoplayPauseDuration' => 5
	);
}

/**
 * Reads settings from file and returns them as php array
 * @return array
 */
function fotorama_read_settings_from_file() {
	$json = file_get_contents(FOTORAMA_SETTINGS_FILE);
	$json = preg_replace('/fotoramaDefaults ?=/', '', $json);
	$json = trim($json);
	$json = trim($json, ';');

	require_once(ABSPATH.'/wp-includes/class-json.php');
	$json_encoder = new Services_JSON(SERVICES_JSON_LOOSE_TYPE);

	return $json_encoder->decode($json);
}

/**
 * Writes settings to file
 * @param  $settings
 * @return void
 */
function fotorama_write_settings_to_file($settings) {
	require_once(ABSPATH.'/wp-includes/class-json.php');
	$json_encoder = new Services_JSON(SERVICES_JSON_LOOSE_TYPE);

	$template = "fotoramaDefaults = {
		width: {width},
		height: {height},

		touchStyle: {touchStyle},
		click: null,
		loop: false,
		autoplay: {autoplay},

		transitionDuration: 333,

		background: null,
		margin: 5,
		minPadding: 10,
		alwaysPadding: false,
		zoomToFit: true,
		cropToFit: false,

		flexible: false,
		fitToWindowHeight: false,

		fullscreen: false,
		fullscreenIcon: {fullscreenIcon},

		vertical: false,

		arrows: {arrows},
		arrowsColor: null,
		arrowPrev: null,
		arrowNext: null,

		nav: {nav},
		navPosition: 'auto',
		navBackground: null,
		dotColor: null,
		thumbSize: null,
		thumbMargin: 5,
		thumbBorderWidth: 3,
		thumbBorderColor: null,

		caption: {caption},
		captionOverlay: false,

		preload: 3,
		preloader: 'dark',

		shadows: true,

		data: null,
		html: null,

		hash: false,
		startImg: 0,

		onShowImg: null,
		onClick: null
	};";
	foreach ($settings as $key => $value) {
		$template = str_replace('{'.$key.'}', method_exists($json_encoder, '_encode') ? $json_encoder->_encode($value) : $json_encoder->encode($value), $template);
	}
	chmod(FOTORAMA_SETTINGS_FILE, 0755);
	$handle = fopen(FOTORAMA_SETTINGS_FILE, 'w+');
	fwrite($handle, $template);
	fclose($handle);
}

?>