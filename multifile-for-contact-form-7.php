<?php
/**
* Plugin Name: Multifile Upload Field for Contact Form 7
* Plugin URI: https://wordpress.org/plugins/multifile-upload-field-for-contact-form-7/
* Description: Adds multiple file field for contact form 7
* Version: 1.0.1
* Author: Spyros Vlachopoulos
* Contributors: KacperSzarek
* Author URI: https://codeable.io/developers/spyros-vlachopoulos/
* License: GPL2
*/

/**
** A base module for [multifile] and [multifile*]
**/

/* Shortcode handler */

add_action( 'wpcf7_init', 'wpcf7_add_shortcode_multifile' );

function wpcf7_add_shortcode_multifile() {
	wpcf7_add_shortcode( array( 'multifile', 'multifile*' ),
		'wpcf7_multifile_shortcode_handler', true );
}

function wpcf7_multifile_shortcode_handler( $tag ) {
	$tag = new WPCF7_Shortcode( $tag );

	if ( empty( $tag->name ) ) {
		return '';
	}

	$validation_error = wpcf7_get_validation_error( $tag->name );

	$class = wpcf7_form_controls_class( $tag->type );

	if ( $validation_error ) {
		$class .= ' wpcf7-not-valid';
	}

	$atts = array();

	$atts['size'] = $tag->get_size_option( '40' );
	$atts['class'] = $tag->get_class_option( $class );
	$atts['id'] = $tag->get_id_option();
	$atts['tabindex'] = $tag->get_option( 'tabindex', 'int', true );
	$atts['accept'] = $tag->get_option( 'accept', null, true);
	$atts['multiple'] = 'multiple';
  
  $accept_wildcard = '';
  $accept_wildcard = $tag->get_option( 'accept_wildcard');
  
	if ( !empty($accept_wildcard)) {
    $atts['accept'] = $atts['accept'] .'/*';
  }
	if ( $tag->is_required() ) {
		$atts['aria-required'] = 'true';
	}

	$atts['aria-invalid'] = $validation_error ? 'true' : 'false';

	$atts['type'] = 'file';
	$atts['name'] = $tag->name.'[]';
  
  $atts = apply_filters('cf7_multifile_atts', $atts);

	$atts = wpcf7_format_atts( $atts );

	$html = sprintf(
		apply_filters('cf7_multifile_input', '<span class="wpcf7-form-control-wrap %1$s"><input %2$s />%3$s</span>', $atts),
		sanitize_html_class( $tag->name ), $atts, $validation_error );

	return $html;
}


/* Encode type filter */

add_filter( 'wpcf7_form_enctype', 'wpcf7_multifile_form_enctype_filter' );

function wpcf7_multifile_form_enctype_filter( $enctype ) {
	$multipart = (bool) wpcf7_scan_shortcode( array( 'type' => array( 'multifile', 'multifile*' ) ) );

	if ( $multipart ) {
		$enctype = 'multipart/form-data';
	}

	return $enctype;
}


/* Validation + upload handling filter */

add_filter( 'wpcf7_validate_multifile', 'wpcf7_multifile_validation_filter', 10, 2 );
add_filter( 'wpcf7_validate_multifile*', 'wpcf7_multifile_validation_filter', 10, 2 );

function wpcf7_multifile_validation_filter( $result, $tag ) {
	$tag = new WPCF7_Shortcode( $tag );

	$name = $tag->name;
	$id = $tag->get_id_option();
  $uniqid = uniqid();

	$original_files_array = isset( $_FILES[$name] ) ? $_FILES[$name] : null;
  
  if ($original_files_array === null) {
    return $result;
  }
  
  $total = count($_FILES[$name]['name']);
  
  $files = array();
  $new_files = array();
  
  for ($i=0; $i<$total; $i++) {
    $files[] = array(
      'name'      => $original_files_array['name'][$i],
      'type'      => $original_files_array['type'][$i],
      'tmp_name'  => $original_files_array['tmp_name'][$i],
      'error'     => $original_files_array['error'][$i],
      'size'      => $original_files_array['size'][$i]
    );
  }
  
  // file loop start
  foreach ($files as $file) {
    
    
    if ( $file['error'] && UPLOAD_ERR_NO_FILE != $file['error'] ) {
      $result->invalidate( $tag, wpcf7_get_message( 'upload_failed_php_error' ) );
      multifile_remove($new_files);
      return $result;
    }

    if ( empty( $file['tmp_name'] ) && $tag->is_required() ) {
      $result->invalidate( $tag, wpcf7_get_message( 'invalid_required' ) );
      return $result;
    }

    if ( ! is_uploaded_file( $file['tmp_name'] ) )
      return $result;

    $allowed_file_types = array();

    if ( $file_types_a = $tag->get_option( 'filetypes' ) ) {
      foreach ( $file_types_a as $file_types ) {
        $file_types = explode( '|', $file_types );

        foreach ( $file_types as $file_type ) {
          $file_type = trim( $file_type, '.' );
          $file_type = str_replace( array( '.', '+', '*', '?' ),
            array( '\.', '\+', '\*', '\?' ), $file_type );
          $allowed_file_types[] = $file_type;
        }
      }
    }

    $allowed_file_types = array_unique( $allowed_file_types );
    $file_type_pattern = implode( '|', $allowed_file_types );

    $allowed_size = apply_filters('cf7_multifile_max_size', 10048576); // default size 1 MB

    if ( $file_size_a = $tag->get_option( 'limit' ) ) {
      $limit_pattern = '/^([1-9][0-9]*)([kKmM]?[bB])?$/';

      foreach ( $file_size_a as $file_size ) {
        if ( preg_match( $limit_pattern, $file_size, $matches ) ) {
          $allowed_size = (int) $matches[1];

          if ( ! empty( $matches[2] ) ) {
            $kbmb = strtolower( $matches[2] );

            if ( 'kb' == $kbmb )
              $allowed_size *= 1024;
            elseif ( 'mb' == $kbmb )
              $allowed_size *= 1024 * 1024;
          }

          break;
        }
      }
    }

    /* File type validation */

    // Default file-type restriction
    if ( '' == $file_type_pattern )
      $file_type_pattern = 'jpg|jpeg|png|gif|pdf|doc|docx|ppt|pptx|odt|avi|ogg|m4a|mov|mp3|mp4|mpg|wav|wmv';

    $file_type_pattern = trim( $file_type_pattern, '|' );
    $file_type_pattern = '(' . $file_type_pattern . ')';
    $file_type_pattern = '/\.' . $file_type_pattern . '$/i';

    if ( ! preg_match( $file_type_pattern, $file['name'] ) ) {
      $result->invalidate( $tag, wpcf7_get_message( 'upload_file_type_invalid' ) );
      multifile_remove($new_files);
      return $result;
    }

    /* File size validation */

    if ( $file['size'] > $allowed_size ) {
      $result->invalidate( $tag, wpcf7_get_message( 'upload_file_too_large' ) );
      multifile_remove($new_files);
      return $result;
    }

    wpcf7_init_uploads(); // Confirm upload dir
    $uploads_dir = wpcf7_upload_tmp_dir();
    $uploads_dir = wpcf7_maybe_add_random_dir( $uploads_dir );

    $filename = $file['name'];
    $filename = wpcf7_canonicalize( $filename );
    $filename = sanitize_file_name( $filename );
    $filename = wpcf7_antiscript_file_name( $filename );
    $filename = wp_unique_filename( $uploads_dir, $filename );

    $new_file = trailingslashit( $uploads_dir ) . $filename;

    if ( false === @move_uploaded_file( $file['tmp_name'], $new_file ) ) {
      $result->invalidate( $tag, wpcf7_get_message( 'upload_failed' ) );
      multifile_remove($new_files);
      return $result;
    }
    
    $new_files[] = $new_file;

    // Make sure the uploaded file is only readable for the owner process
    @chmod( $new_file, 0400 );


  
  }
  
  // file loop end
  $zipped_files = trailingslashit( $uploads_dir ).$uniqid.'.zip';
  $zipping = multifile_create_zip($new_files, $zipped_files);
  @chmod( $zipped_files, 0400 );
  
  if ($zipping === false) {
    $result->invalidate( $tag, wpcf7_get_message( 'zipping_failed' ) );
    multifile_remove($new_files);
    return $result;
  }
  
  multifile_remove($new_files);
  
  if ( $submission = WPCF7_Submission::get_instance() ) {
    $submission->add_uploaded_file( $name, $zipped_files );
  }
  
	return $result;
}


/* Messages */

add_filter( 'wpcf7_messages', 'wpcf7_multifile_messages' );

function wpcf7_multifile_messages( $messages ) {
	return array_merge( $messages, array(
		'upload_failed' => array(
			'description' => __( "Uploading a file fails for any reason", 'contact-form-7' ),
			'default' => __( "There was an unknown error uploading the file.", 'contact-form-7' )
		),
    
		'zipping_failed' => array(
			'description' => __( "Zipping files fails for any reason", 'contact-form-7' ),
			'default' => __( "There was an unknown error zippng the files.", 'contact-form-7' )
		),

		'upload_file_type_invalid' => array(
			'description' => __( "Uploaded file is not allowed for file type", 'contact-form-7' ),
			'default' => __( "You are not allowed to upload files of this type.", 'contact-form-7' )
		),

		'upload_file_too_large' => array(
			'description' => __( "Uploaded file is too large", 'contact-form-7' ),
			'default' => __( "The file is too big.", 'contact-form-7' )
		),

		'upload_failed_php_error' => array(
			'description' => __( "Uploading a file fails for PHP error", 'contact-form-7' ),
			'default' => __( "There was an error uploading the file.", 'contact-form-7' )
		)
	) );
}


/* Tag generator */

add_action( 'wpcf7_admin_init', 'wpcf7_add_tag_generator_multifile', 50 );

function wpcf7_add_tag_generator_multifile() {
	$tag_generator = WPCF7_TagGenerator::get_instance();
	$tag_generator->add( 'multifile', __( 'multifile', 'contact-form-7' ),
		'wpcf7_tag_generator_multifile' );
}

function wpcf7_tag_generator_multifile( $contact_form, $args = '' ) {
	$args = wp_parse_args( $args, array() );
	$type = 'multifile';

	$description = __( "Generate a form-tag for a file uploading field. For more details, see %s.", 'contact-form-7' );

	$desc_link = wpcf7_link( __( 'http://contactform7.com/file-uploading-and-attachment/', 'contact-form-7' ), __( 'File Uploading and Attachment', 'contact-form-7' ) );

?>
<div class="control-box">
<fieldset>
<legend><?php echo sprintf( esc_html( $description ), $desc_link ); ?></legend>

<table class="form-table">
<tbody>
	<tr>
	<th scope="row"><?php echo esc_html( __( 'Field type', 'contact-form-7' ) ); ?></th>
	<td>
		<fieldset>
		<legend class="screen-reader-text"><?php echo esc_html( __( 'Field type', 'contact-form-7' ) ); ?></legend>
		<label><input type="checkbox" name="required" /> <?php echo esc_html( __( 'Required field', 'contact-form-7' ) ); ?></label>
		</fieldset>
	</td>
	</tr>

	<tr>
	<th scope="row"><label for="<?php echo esc_attr( $args['content'] . '-name' ); ?>"><?php echo esc_html( __( 'Name', 'contact-form-7' ) ); ?></label></th>
	<td><input type="text" name="name" class="tg-name oneline" id="<?php echo esc_attr( $args['content'] . '-name' ); ?>" /></td>
	</tr>

	<tr>
	<th scope="row"><label for="<?php echo esc_attr( $args['content'] . '-limit' ); ?>"><?php echo esc_html( __( "File size limit (bytes)", 'contact-form-7' ) ); ?></label></th>
	<td><input type="text" name="limit" class="filesize oneline option" id="<?php echo esc_attr( $args['content'] . '-limit' ); ?>" /></td>
	</tr>

	<tr>
	<th scope="row"><label for="<?php echo esc_attr( $args['content'] . '-filetypes' ); ?>"><?php echo esc_html( __( 'Acceptable file types', 'contact-form-7' ) ); ?></label></th>
	<td><input type="text" name="filetypes" class="filetype oneline option" id="<?php echo esc_attr( $args['content'] . '-filetypes' ); ?>" /></td>
	</tr>
  
  
	<tr>
	<th scope="row"><label for="<?php echo esc_attr( $args['content'] . '-accept' ); ?>"><?php echo esc_html( __( 'Accept input attribute', 'contact-form-7' ) ); ?></label></th>
	<td><input type="text" name="accept" class="filetype oneline option" id="<?php echo esc_attr( $args['content'] . '-accept' ); ?>" /></td>
	</tr>
  
  <tr>
  <th scope="row"><label for="<?php echo esc_attr( $args['content'] . '-accept_wildcard' ); ?>"><?php echo esc_html( __( 'Add  accept wildcard /*', 'contact-form-7' ) ); ?></label></th>
	<td>
		<fieldset>
    <input type="text" name="accept_wildcard" class="filetype oneline option" id="<?php echo esc_attr( $args['content'] . '-accept_wildcard' ); ?>" /><small><?php echo __('Type "yes" to add wildcard'); ?></small>
		</fieldset>
	</td>
	</tr>

	<tr>
	<th scope="row"><label for="<?php echo esc_attr( $args['content'] . '-id' ); ?>"><?php echo esc_html( __( 'Id attribute', 'contact-form-7' ) ); ?></label></th>
	<td><input type="text" name="id" class="idvalue oneline option" id="<?php echo esc_attr( $args['content'] . '-id' ); ?>" /></td>
	</tr>

	<tr>
	<th scope="row"><label for="<?php echo esc_attr( $args['content'] . '-class' ); ?>"><?php echo esc_html( __( 'Class attribute', 'contact-form-7' ) ); ?></label></th>
	<td><input type="text" name="class" class="classvalue oneline option" id="<?php echo esc_attr( $args['content'] . '-class' ); ?>" /></td>
	</tr>

</tbody>
</table>
</fieldset>
</div>

<div class="insert-box">
	<input type="text" name="<?php echo $type; ?>" class="tag code" readonly="readonly" onfocus="this.select()" />

	<div class="submitbox">
	<input type="button" class="button button-primary insert-tag" value="<?php echo esc_attr( __( 'Insert Tag', 'contact-form-7' ) ); ?>" />
	</div>

	<br class="clear" />

	<p class="description mail-tag"><label for="<?php echo esc_attr( $args['content'] . '-mailtag' ); ?>"><?php echo sprintf( esc_html( __( "To attach the file uploaded through this field to mail, you need to insert the corresponding mail-tag (%s) into the File Attachments field on the Mail tab.", 'contact-form-7' ) ), '<strong><span class="mail-tag"></span></strong>' ); ?><input type="text" class="mail-tag code hidden" readonly="readonly" id="<?php echo esc_attr( $args['content'] . '-mailtag' ); ?>" /></label></p>
</div>
<?php
}


/* Warning message */

add_action( 'wpcf7_admin_notices', 'wpcf7_multifile_display_warning_message' );

function wpcf7_multifile_display_warning_message() {
	if ( ! $contact_form = wpcf7_get_current_contact_form() ) {
		return;
	}

	$has_tags = (bool) $contact_form->form_scan_shortcode(
		array( 'type' => array( 'multifile', 'multifile*' ) ) );

	if ( ! $has_tags ) {
		return;
	}

	$uploads_dir = wpcf7_upload_tmp_dir();
	wpcf7_init_uploads();

	if ( ! is_dir( $uploads_dir ) || ! wp_is_writable( $uploads_dir ) ) {
		$message = sprintf( __( 'This contact form contains file uploading fields, but the temporary folder for the files (%s) does not exist or is not writable. You can create the folder or change its permission manually.', 'contact-form-7' ), $uploads_dir );

		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}
}



/* creates a compressed zip file */
function multifile_create_zip($files = array(),$destination = '',$overwrite = false) {
	//if the zip file already exists and overwrite is false, return false
	if(file_exists($destination) && !$overwrite) { return false; }
	//vars
	$valid_files = array();
	//if files were passed in...
	if(is_array($files)) {
		//cycle through each file
		foreach($files as $file) {
			//make sure the file exists
			if(file_exists($file)) {
				$valid_files[] = $file;
			}
		}
	}
	//if we have good files...
	if(count($valid_files)) {
		//create the archive
		$zip = new ZipArchive();
		if($zip->open($destination,$overwrite ? ZIPARCHIVE::OVERWRITE : ZIPARCHIVE::CREATE) !== true) {
			return false;
		}
		//add the files
		foreach($valid_files as $file) {
			$zip->addFile($file,basename($file));
		}
		//debug
		//echo 'The zip archive contains ',$zip->numFiles,' files with a status of ',$zip->status;
		
		//close the zip -- done!
		$zip->close();
		
		//check to make sure the file exists
		return file_exists($destination);
	}
	else
	{
		return false;
	}
}


function multifile_remove($new_files) {
  if (!empty($new_files)) {
    foreach($new_files as $to_delete) {
      @unlink( $to_delete );
      @rmdir( dirname( $to_delete ) ); // remove parent dir if it's removable (empty).
    }
  }
}
