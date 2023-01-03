<?php
/**
 * Plugin Name: Gravity Forms Merge PDFs
 * Description: Adds a merged PDFs field and inlines PDF uploads into Gravity PDF exports.
 * Author: Gennady Kovshenin
 * Version: 1.2.0
 */

defined( 'ABSPATH' ) || exit;

use PDFMerger\PDFMerger;

define( 'GRAVITY_MERGE_PDFS_DNONCE_MULTIPLIER', 687 ); // multiplier for the basic "fake" nonces protection
define( 'GRAVITY_MERGE_PDFS_DNONCE_SECRET', 'XF09=*/=JH' ); // secret word for the basic "fake" nonces protection
define( 'GRAVITY_MERGE_PDFS_USE_NONCES', false ); // whether to use real WP nonces

add_action( 'gform_loaded', function() {
	require_once __DIR__ . '/class-gf-merge-pdfs-field.php';
} );

function gf_merge_pdfs_get_files( $entry_id ) {
    
	$entry = GFAPI::get_entry( $entry_id );
	$form = GFAPI::get_form( $entry['form_id'] );

	$fields = [];
	
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
			}
		}
	}
	
	return $files;
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

/**
* Tests if the PDF generation works with the old library, otherwise falls back to shell commands
**/
function gf_merge_pdf_fallback( string $path ) : string {
    // Try the PDF, fix or error out.
    $test = new PDFMerger;
    $test->addPDF( $path );
    //echo $path.'<br>';
    try {
        $test->merge( 'string' );
    } catch ( Exception $e ) {
        
        // Unreadable, let's try to fix with gs
        error_log( "$path " . $e->getMessage() );

        $repaired_path = tempnam( get_temp_dir(), 'pdf' );

        exec( sprintf( 'gs -o %s -sDEVICE=pdfwrite -dPDFSETTINGS=/prepress %s',
            escapeshellarg( $repaired_path ),
            escapeshellarg( $path ) ) );

        if ( filesize( $repaired_path ) ) {
            $test = new PDFMerger;
            $test->addPDF( $repaired_path );
            try {
                $test->merge( 'string' );
            } catch ( Exception $e ) {
                // Couldn't repair, let's error
                error_log( "$path " . $e->getMessage() );
                $add_error = true;
            }

            $path = $repaired_path;
        } else {
            $add_error = true;
        }

        if ( ! empty( $add_error ) ) {
            $error = new TCPDF();
            $error->AddPage();
            $error->SetFont( '', '', 8 );
            $error->writeHTML( "An error occurred while retrieving the following PDF: $uri. Please download/view it manually by clicking the URL." );
            $error->Output( $repaired_path, 'F' );

            $path = $repaired_path;
        }
    }
    
    return $path;
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
    
	$files = gf_merge_pdfs_get_files( $entry_id );
	
	require __DIR__ . '/lib/PDFMerger/PDFMerger.php';
	
	if( !empty( $files ) ){
		$pdf = new PDFMerger;
		
		foreach ( $files as $file ) {
			list( $form_id, $field_id, $entry_id, $path, $uri ) = $file;
	
			$path = gf_merge_pdf_fallback( $path );
	
			$pdf->addPDF( $path );
		}
		
		$pdf->merge( 'browser', 'merged.pdf' );
		exit;
	}

	
} );

add_filter( 'gfpdf_mpdf_class', function( $mpdf, $form, $entry, $settings, $helper ) {
	if ( ! GFCommon::get_fields_by_type( $form, 'merge_pdfs' ) ) {
		return $mpdf;
	}
    
	if ( ! $files = gf_merge_pdfs_get_files( $entry['id'] ) ) {
		return $mpdf;
	}
		
	file_put_contents( $output = tempnam( get_temp_dir(), 'merge_pdfs' ), $mpdf->Output( '', 'S' ) );

	require __DIR__ . '/lib/PDFMerger/PDFMerger.php';

	$pdf = new PDFMerger;
	$pdf->addPDF( $output );
	
	foreach ( $files as $file ) {
		list( $form_id, $field_id, $entry_id, $path, $uri ) = $file;
		//echo $path.'<br>';
		$path = gf_merge_pdf_fallback( $path );
		$pdf->addPDF( $path );
	}
    
	switch ( $helper->get_output_type() ) {
		case 'DISPLAY':		
			$pdf->merge( 'browser', $helper->get_filename() );
			exit;
		case 'DOWNLOAD':
			$pdf->merge( 'download', $helper->get_filename() );
			exit;
		case 'SAVE':
			return new class( $pdf ) {
				public function __construct( $pdf ) {
					$this->pdf = $pdf;
				}
				public function Output() {
					return $this->pdf->merge( 'string' );
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
