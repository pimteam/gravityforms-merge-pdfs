<?php
/**
 * Plugin Name: Gravity Forms Merge PDFs
 * Description: Adds a merged PDFs field and inlines PDF uploads into Gravity PDF exports.
 * Authors: Gennady Kovshenin, Bob Handzhiev
 * Version: 1.6.2                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        
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
function gf_merge_pdfs_output( $files, $errors, $entry_id, $file_name = '' ) {
    $dir = wp_upload_dir();
    $tmp_dir = get_temp_dir();
    
    // stored file exists?
    $store_path = GFFormsModel :: get_upload_root();
    $stored_file = $store_path ."merged/".$entry_id.".pdf";
    $outputName = $file_name ? $file_name : "merged-".$entry_id.".pdf";
    $cmd_name = "merged-".$entry_id.".pdf";
    
    if(file_exists($stored_file)) {
        // delete all tmp files    
        foreach($files as $file) {
            [$form_id, $field_id, $entry_id, $path, $uri] = $file;
            if(strstr($path, $tmp_dir)) unlink($path);
        }
    
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
        require('fpdf/fpdf.php');   
        
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
    
    
    $cmd = "gs -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -sOutputFile=$cmd_name ";
    //Add each pdf file to the end of the command
    foreach($files as $file) {
        [$form_id, $field_id, $entry_id, $path, $uri] = $file;
        
        if(!is_string($path)) continue;
        
        $cmd .= $path." ";
    }
    
    $result = shell_exec($cmd);
    
    // delete all tmp files    
    foreach($files as $file) {
        [$form_id, $field_id, $entry_id, $path, $uri] = $file;
        if(strstr($path, $tmp_dir)) unlink($path);
    }
    
    // copy the merged file into stored file location
    if (!file_exists($store_path.'merged')) {
        mkdir($store_path.'merged', 0755, true);
    }
    copy($cmd_name, $stored_file);
    
    header('Cache-control: private');
    header('Content-Type: application/pdf');
    //header('Content-Length: '.filesize($local_file));
   // header('Content-Disposition: attachment; filename="'.$outputName.'";');
    header('Content-disposition: inline; filename="'.$outputName.'"');
    header('Content-Transfer-Encoding: binary');
    header('Accept-Ranges: bytes');
    ob_clean();
    flush();
    if (readfile($cmd_name)) {
        unlink($cmd_name);
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
					gf_merge_pdfs_output( $this->files, $this->errors ?? [], $this->entry_id, $file_name );
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
    if(file_exists($path ."merged/".$entry_id.".pdf")) unlink($path ."merged/".$entry_id.".pdf");
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
