<?php
/**
 * Plugin Name: Gravity Forms Merge PDFs
 * Plugin URI: https://github.com/pimteam/gravityforms-merge-pdfs
 * Description: Adds a merged PDFs field and inlines PDF uploads into Gravity PDF exports.
 * Authors: Gennady Kovshenin, Bob Handzhiev
 * Version: 1.7.2
 */

defined( 'ABSPATH' ) || exit;

define( 'GRAVITY_MERGE_PDFS_DNONCE_MULTIPLIER', 687 ); // multiplier for the basic "fake" nonces protection
define( 'GRAVITY_MERGE_PDFS_DNONCE_SECRET', 'XF09=*/=JH' ); // secret word for the basic "fake" nonces protection
define( 'GRAVITY_MERGE_PDFS_USE_NONCES', false ); // whether to use real WP nonces

//use PDFMerger\PDFMerger;

add_action( 'gform_loaded', function() {
	require_once __DIR__ . '/class-gf-merge-pdfs-field.php';
} );

function gf_merge_pdfs_get_files( int $entry_id ) : iterable {
    
	$entry = GFAPI::get_entry( $entry_id );
	$form = GFAPI::get_form( $entry['form_id'] );
	
	$fields = [];
	$errors = [];
	
	if( isset( $form['fields'] ) && !empty( $form['fields'] ) ){
		foreach ( $form['fields'] as $field ) {
			if ( $field->type == 'fileupload' && $field->cssClass != 'skip_merge' ) {
				$fields[] = [
					$form['id'],
					$field->id,
					$entry_id,
				];
			}
	
			if ( $field->type == 'form' ) {
				$parent_entry = new GPNF_Entry( $entry );
				foreach ( $parent_entry->get_child_entries( $field->id ) as $nested_entry ) {
					$nested_form = GFAPI::get_form( $nested_entry['form_id'] );
					foreach ( $nested_form['fields'] as $nested_field ) {
						if ( $nested_field->type == 'fileupload' && $nested_field->cssClass != 'skip_merge' ) {
							$fields[] = [
								$nested_form['id'],
								$nested_field->id,
								$nested_entry['id'],
							];
						}
					}
				}
			}
		}
	}
	
	$files = [];
	
	if(!empty($_GET['gf_merge_pdfs'])) {
        $pdfs = GPDFAPI::get_entry_pdfs( $entry_id );	
        $keys = array_keys($pdfs);
        if(!empty($keys[0])) {
            $pdf_path = GPDFAPI::create_pdf( $entry_id, $keys[0]);
            $files[] =  [$entry['form_id'], 0, $entry_id, $pdf_path, '' ]; 
        }        
    }
	
	if( !empty( $fields ) ){
		foreach ( $fields as $field ) {
			list( $form_id, $field_id, $entry_id ) = $field;
		
			$entry = GFAPI::get_entry( $entry_id );
			
			$form = GFAPI::get_form( $form_id );
			$field = GFAPI::get_field( $form, $field_id );
			
			if ( empty( $entry[ $field->id ] ) ) {
				continue;
			}
			            
			foreach ( $field->multipleFiles ? json_decode( $entry[ $field->id ], true ) : [ $entry[ $field->id ] ] as $uri ) {
// 				$info = $field::get_file_upload_path_info( $uri, $entry_id );
// 				$file = $info['path'] . $info['file_name']

                $file = convert_url_to_path( $uri );

				if ( !file_exists( $file )) {
					// try to copy it from the backup location
					$file_dupe = str_replace('gravity_forms', 'gravity_duplicate', $file);
					copy($file_dupe, $file);
				}
                
				if ( file_exists( $file ) && is_readable( $file ) ) {                    
                    
					if ( strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) === 'pdf' ) {
						$files[] = [
							$form_id,
							$field_id,
							$entry_id,
							$file,
							$uri,
						];
					}
				}
				else {
                    $errors[] = $file;
				}
			}
		}
	}

	return [ $files, $errors ];
}

function convert_url_to_path( $url ) {
  // Some info got broken due to http / https issue so let's fix it by replacing base URL
  $dir = wp_get_upload_dir();
  $baseurl = $dir['baseurl'];
  if( strstr( $url, 'http://') ) $baseurl = str_replace('https:','http:', $baseurl);
  
  return str_replace( 
	  $baseurl, 
	  $dir['basedir'], 
	  $url
  );
}

// Actually merges and outputs the files using shell commands
function gf_merge_pdfs_output( $files, $errors, $entry_id, $file_name = '', $display = true ) {
    $dir = wp_upload_dir();
    $tmp_dir = get_temp_dir();
    
    // stored file exists?
    //$store_path = GFFormsModel :: get_upload_root();
	$store_path = $dir['basedir'].'/gravity_forms/';
    $outputName = $file_name ? $file_name : "merged-".$entry_id.".pdf";
	$stored_file = $store_path ."merged/merged-".$entry_id.".pdf";
    $cmd_name = "merged-".$entry_id.".pdf";

    if(file_exists($stored_file) ) {

        // delete all tmp files    
        foreach($files as $file) {
            [$form_id, $field_id, $entry_id, $path, $uri] = $file;
            #if(strstr($path, $tmp_dir)) unlink($path);
        }

        if(!$display) return $stored_file;
    
        // output the file instead of merging again
        header('Cache-control: private');
        header('Content-Type: application/pdf');    
       // header('Content-Disposition: attachment; filename="'.$outputName.'";');
       header('Content-disposition: inline; filename="'.$outputName.'"');
        readfile( $stored_file ); 
        exit;
    }    
    
    // if there are errors, create a file with them
    if( count( $errors ) ) {
        require_once('fpdf/fpdf.php');
        
        $file_str = implode(', ', array_map( fn( $e ) => basename($e), $errors ) );
        
        $cnt_files = count($files);
        if( $cnt_files ) {
            $file_str .= sprintf("\nThere %s %d valid %s for this entry included after this page.", ($cnt_files > 1 ? 'are' : 'is'), $cnt_files, ($cnt_files > 1 ? 'files' : 'file') );
        }
        else $file_str .= "\nThere are no valid files for this entry.";
        
        $error_file = $dir['path']."/errors-".$entry_id.".pdf";
        
        $pdf = new FPDF();
        $pdf->AddPage();
        $pdf->SetFont('Arial','B',12);
        $pdf->MultiCell( 0 , 10, 'The following files are missing or unreadable: ' . $file_str );
        $pdf->Output( 'F', $error_file );
        
        // fake array, we only need path
        array_unshift( $files,  [0, 0, 0, $error_file, '' ] ); 
    }
    
    $cmd_path = $store_path ."merged/". $cmd_name;
    $cmd = "gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -sOutputFile=$cmd_path ";
    //Add each pdf file to the end of the command
    foreach($files as $file) {
        [$form_id, $field_id, $entry_id, $path, $uri] = $file;
        
        if(!is_string($path)) continue;
        
        $cmd .= '"'.$path.'" ';
    }
    
    $result = shell_exec($cmd);

    // delete all tmp files    
    foreach($files as $file) {
        [$form_id, $field_id, $entry_id, $path, $uri] = $file;
        #if(strstr($path, $tmp_dir)) unlink($path);
    }
    
    // copy the merged file into stored file location
    if (!file_exists($store_path.'merged')) {
        mkdir($store_path.'merged', 0755, true);
    }
    copy($cmd_path, $stored_file);

    // allow saving without displaying 
    if(!$display) return $stored_file;
    
    header('Cache-control: private');
    header('Content-Type: application/pdf');
    //header('Content-Length: '.filesize($local_file));
   // header('Content-Disposition: attachment; filename="'.$outputName.'";');
    header('Content-disposition: inline; filename="'.$outputName.'"');
    header('Content-Transfer-Encoding: binary');
    header('Accept-Ranges: bytes');
    ob_clean();
    flush();
    if (readfile($cmd_path)) {
        //unlink($cmd_name);
    }   
    exit;

}

add_action( 'init', function() {
	if ( ! $entry_id = $_GET['gf_merge_pdfs'] ?? 0 ) {
		return;
	}
    
	if ( GRAVITY_MERGE_PDFS_USE_NONCES and ! wp_verify_nonce( $_GET['nonce'] ?? null, "gf_merge_pdfs_$entry_id" ) ) {
		return;
	}
	
	if( !is_user_logged_in() ) return;
	
	// check fake nonce
    $check_dnonce = substr( md5( ( $entry_id * GRAVITY_MERGE_PDFS_DNONCE_MULTIPLIER ) . GRAVITY_MERGE_PDFS_DNONCE_SECRET ), 0, 10 );
    
    if( $check_dnonce != $_GET['dnonce'] ) return;
    
	[$files, $errors] = gf_merge_pdfs_get_files( $entry_id );
	
		
	gf_merge_pdfs_output( $files, $errors, $entry_id );	
} );

add_filter( 'gfpdf_mpdf_class', function( $mpdf, $form, $entry, $settings, $helper ) {

    if(!empty($_GET['gf_merge_pdfs'])) return $mpdf;

	if ( ! GFCommon::get_fields_by_type( $form, 'merge_pdfs' ) ) {
		return $mpdf;
	}

    [$files, $errors] = gf_merge_pdfs_get_files( $entry['id'] );
	if ( ! $files ) {
		return $mpdf;
	}

	// get output file name
	$model_pdf = GPDFAPI::get_mvc_class( 'Model_PDF' );
	$file_name = $model_pdf -> get_pdf_name( $settings, $entry ) . '.pdf';

	file_put_contents( $output = tempnam( get_temp_dir(), 'merge_pdfs' ), $mpdf->Output( '', 'S' ) );
	//die($output);
	array_unshift($files, [0, 0,0, $output, '']);

	switch ( $helper->get_output_type() ) {
		case 'DISPLAY':
			gf_merge_pdfs_output( $files, $errors, $entry['id'], $file_name);
			exit;
		case 'DOWNLOAD':
			gf_merge_pdfs_output( $files, $errors, $entry['id'], $file_name );
			exit;
		case 'SAVE':
			return new class( $files, $errors, $entry['id'] ) {
				public function __construct( $files, $errors, $entry_id ) {
					$this->files = $files;
					$this->errors = $errors;
					$this->entry_id = $entry_id;
				}
				public function Output() {
					gf_merge_pdfs_output( $this->files, $this->errors ?? [], $this->entry_id, $file_name, false );
				}
			};
	}

	return $mpdf;
}, 10, 5 );

false && add_filter( 'gravityflowpdf_mpdf', function( $mpdf, $body, $file_path, $entry, $step ) {
	$form = GFAPI::get_form( $entry['form_id'] );

	if ( ! GFCommon::get_fields_by_type( $form, 'merge_pdfs' ) ) {
		return $mpdf;
	}
    
	if ( ! $files = gf_merge_pdfs_get_files( $entry['id'] ) ) {
		return $mpdf;
	}

	$mpdf->WriteHTML( $body );
	file_put_contents( $output = tempnam( get_temp_dir(), 'merge_pdfs' ), $mpdf->Output( '', 'S' ) );
	
	return new class( $mpdf,  $entry['id']) {
		public function __construct( $mpdf, $entry_id ) {			
			$this->mpdf = $mpdf;
			$this->entry_id = $entry_id;
		}
		public function Output() {
			array_unshift($files, [0, 0,0, $file_path, '']);	
            gf_merge_pdfs_output( $files, [], $this->entry_id );
		}
		public function __call( $f, $args ) {
			return call_user_func_array( [ $this->mpdf, $f ], $args );
		}
	};
}, 10, 5 );

/*
false && add_filter( 'gravityflowpdf_mpdf', function( $mpdf, $body, $file_path, $entry, $step ) {
	$form = GFAPI::get_form( $entry['form_id'] );

	if ( ! GFCommon::get_fields_by_type( $form, 'merge_pdfs' ) ) {
		return $mpdf;
	}
    
	if ( ! $files = gf_merge_pdfs_get_files( $entry['id'] ) ) {
		return $mpdf;
	}

	$mpdf->WriteHTML( $body );
	file_put_contents( $output = tempnam( get_temp_dir(), 'merge_pdfs' ), $mpdf->Output( '', 'S' ) );

	require __DIR__ . '/lib/PDFMerger/PDFMerger.php';

	$pdf = new PDFMerger;
	$pdf->addPDF( $output );
	foreach ( $files as $file ) {
		list( $form_id, $field_id, $entry_id, $path ) = $file;
		$pdf->addPDF( $path );
	}

	return new class( $pdf, $mpdf ) {
		public function __construct( $pdf, $mpdf ) {
			$this->pdf = $pdf;
			$this->mpdf = $mpdf;
		}
		public function Output() {
			file_put_contents( $file_path, $this->pdf->merge( 'string' ) );
		}
		public function __call( $f, $args ) {
			return call_user_func_array( [ $this->mpdf, $f ], $args );
		}
	};
}, 10, 5 );
*/

/**
* Delete stored merge files on updating or deleting an entry
**/
function gf_merge_pdfs_entry_updated($form_id, $entry_id) {
    $path = GFFormsModel :: get_upload_root();    

	// we had some mix of file names in the past so let's make sure both versions are deleted
    if(file_exists($path ."merged/".$entry_id.".pdf")) unlink($path ."merged/".$entry_id.".pdf");
	if(file_exists($path ."merged/merged-".$entry_id.".pdf")) unlink($path ."merged/merged-".$entry_id.".pdf");
}

add_action('gform_post_update_entry', function($entry, $original_entry){
    gf_merge_pdfs_entry_updated($entry['form_id'], $entry['id']);
}, 10, 2);
add_action('gform_after_update_entry', function($form, $entry_id){        
    gf_merge_pdfs_entry_updated($form['id'], $entry_id);
}, 10, 2);

add_action('gform_delete_entry', function($entry_id) {
    gf_merge_pdfs_entry_updated(0, $entry_id);
});


// check for updates
add_action('init', function(){
    if (!function_exists('shell_exec')) {        
        deactivate_plugins(plugin_basename(__FILE__));        
        add_action('admin_notices', function(){
            gf_merge_pdf_activation_notice('The shell_exec function is disabled on this server. Please enable it to use Gravity Forms Merge PDFs.');
        });
    }
    
    if(!class_exists('GPDFAPI')) {
       deactivate_plugins(plugin_basename(__FILE__));        
        add_action('admin_notices', function(){
            gf_merge_pdf_activation_notice('The Gravity PDF API must be installed. Please enable it to use Gravity Forms Merge PDFs.');
        });
    }

    $domain = empty($_SERVER['SERVER_NAME']) ? '' : $_SERVER['SERVER_NAME'];	

	if($domain) {
		include dirname( __FILE__ ).'/plugin-update-checker/plugin-update-checker.php';	
		$MyUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
			'https://cerebralconsulting.net/hosted-plugins/gf-merge-pdf.json',
		    dirname( __FILE__ ).'/loader.php',
		    'gf_merge_pdf'
		);
	}
});

// activation
register_activation_hook( __FILE__, 'gravity_merge_pdfs_activate' );
function gravity_merge_pdfs_activate() {
    if (!function_exists('shell_exec')) {        
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('The shell_exec function is disabled on this server. Please enable it to use this plugin.');
    }
    
    if(!class_exists('GPDFAPI')) {        
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('The Gravity PDF API must be installed. Please enable it to use this plugin.');
    }
}

function gf_merge_pdf_activation_notice($notice) {
    echo '<div class="error">';
    echo '<p>';
    echo $notice;
    echo '</p>';
    echo '</div>';
}

// bulk downloader
add_filter(
	'gform_entry_list_bulk_actions',
	function( $actions ) {
		$actions['download_merged_pdf'] =  'Download Merged PDF';

		return $actions;
	}
);

// process the bulk actions
add_action( 'gform_entry_list_action', function ( $action, $entries, $form_id ) : void {
    if(!current_user_can('manage_options')) return;

    if ( $action == 'download_merged_pdf'){
		// get configurable file names -for the individual merge and for the whole download
		// use get_form_pdfs( $form_id )
		$pdfs = GPDFAPI ::  get_form_pdfs( $form_id );
		$pdf_config = array_filter($pdfs, function($pdf) {
			if(!empty($pdf['cc_bulk_download'])) return true;
			return false;
		});
		$pdf_config = reset($pdf_config);

		// prepare individual entry PDF file name
		if(!empty($pdf_config)) {
			$model_pdf = GPDFAPI::get_mvc_class( 'Model_PDF' );
			$settings = GPDFAPI :: get_pdf( $form_id, $pdf_config['id'] );
		}

		$final_files = [];
        //$form = GFAPI::get_form( $form_id );
        foreach ( $entries as $entry_id ) {
			// generate the PDF
			[$files, $errors] = gf_merge_pdfs_get_files( $entry_id );

			$file = gf_merge_pdfs_output($files, $errors, $entry_id, '', false);

			if($file) $final_files[] = ['file' => $file, 'entry_id' => $entry_id];
        }

		$dir = wp_upload_dir();
        $zip = new ZipArchive();
		$file_name = empty($pdf_config) ? 'form-'.$form_id : $pdf_config['name'];
		$zip_file_name = $dir['basedir'].'/gravity_forms/merged/'.$file_name.'.zip';
		if ($zip->open($zip_file_name, ZipArchive::CREATE) !== true) {
			die("Error: Cannot create zip archive");
		}

		foreach ($final_files as $file) {
			// Make sure the file exists before adding it to the archive
			$f = $file['file'];
			$entry_id = $file['entry_id'];
			if (file_exists($f)) {
				// Add the file to the archive with its original name
				$file_name = empty($settings) ? basename($f) : $model_pdf->get_pdf_name( $settings, GFAPI :: get_entry($entry_id) ).'.pdf';
				$zip->addFile($f, $file_name);
			} else {
				echo "Warning: File not found - $f\n";
			}
		}

		// Close the zip archive
		$zip->close();
		header("Location: ".site_url("?noheader=1&download_merged_zip=".$form_id));
		exit;
    }
}, 10, 3 );

add_action('wp_loaded', function() {
	if(empty($_GET['download_merged_zip']) or !current_user_can('manage_options')) return;

	$form_id = intval($_GET['download_merged_zip'] ?? 0);

	$pdfs = GPDFAPI ::  get_form_pdfs( $form_id );
		$pdf_config = array_filter($pdfs, function($pdf) {
			if(!empty($pdf['cc_bulk_download'])) return true;
			return false;
		});
	$pdf_config = reset($pdf_config);
	$file_name = empty($pdf_config) ? 'form-'.$form_id : $pdf_config['name'];

	$dir = wp_upload_dir();
	$zip_file_name = $dir['basedir'].'/gravity_forms/merged/'.$file_name.'.zip';
	header('Cache-control: private');
    header('Content-Type: application/zip');
    //header('Content-Length: '.filesize($local_file));
   // header('Content-Disposition: attachment; filename="'.$outputName.'";');
    header('Content-disposition: inline; filename="'.basename($zip_file_name).'"');
    header('Content-Transfer-Encoding: binary');
    header('Accept-Ranges: bytes');
    ob_clean();
    flush();
    if (readfile($zip_file_name)) {
        unlink($zip_file_name);
    }
    exit;
});

/**
 * Add setting in the Gravity PDF section for each form that allows setting a specific PDF as a bulk-download config for the form
 * This means that its name and naming template will be used for the generated bulk download
 **/
add_filter( 'gfpdf_registered_fields', function( $gfpdf_settings ) {

   /**
    * Ensure you prefix the array key and ID to prevent any conflicts
    */

   if ( isset( $gfpdf_settings['general'] ) ) {
        $gfpdf_settings['form_settings']['cc_bulk_download_default'] = [
            'id'   => 'cc_bulk_download',
            'name' => 'Gravity Forms Merge PDFs Bulk Download Settings',
            'type' => 'checkbox',
            'desc' => "Use this Gravity PDF feed's name and naming template for bulk merged PDF downloads.",
            'std'  => '#CCCCCC'
        ];
   }

    return $gfpdf_settings;
} );

