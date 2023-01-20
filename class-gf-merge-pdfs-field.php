<?php
defined( 'ABSPATH' ) || exit;

use  Gravity_Forms\Gravity_Forms\Orders\Summaries\GF_Order_Summary;
use  Gravity_Forms\Gravity_Forms\Orders\Factories\GF_Order_Factory;

class GF_Merge_PDFs_Field extends GF_Field {

	public $type = 'merge_pdfs';

	public function get_form_editor_field_settings() {
		return [
			'label_setting',
			'visibility_setting',
		];
	}

	public function get_form_editor_field_title() {
		return esc_attr( 'Merged PDFs' );
	}

	public function get_form_editor_field_description() {
		return esc_attr( 'Adds a merged PDFs field and inlines PDF uploads into Gravity PDF exports if present.' );
	}

	public function get_form_editor_field_icon() {
		return 'gform-icon--duplicate';
	}

	public function get_form_editor_button() {
		return array(
			'group' => 'advanced_fields',
			'text'  => $this->get_form_editor_field_title(),
		);
	}
	
	public function get_form_editor_inline_script_on_page_render() {
		ob_start();
		?>
			function SetDefaultValues_merge_pdfs( field ) {
				field.label = 'Merged PDFs';
			}
		<?php
		return ob_get_clean();
	}

	private function get_download_link( $entry ) {
		$link_text = apply_filters( 'gf_merge_pdfs_link_text', 'View all PDFs', $entry );
		return sprintf( '<a target="_blank" href="%s">%s</a>', esc_attr( $this->generate_merge_url( $entry['id'] ?? null ) ), $link_text );
	}

	public function get_value_entry_list( $value, $entry, $field_id, $columns, $form ) {
		return $this->get_download_link( $entry );
	}

	public function get_value_entry_detail( $value, $currency = '', $use_text = false, $format = 'html', $media = 'screen' ) {
		if ( ! class_exists( 'GFEntryDetail' ) ) {
			if ( ! $entry = gravityview()->request->is_entry() ) {
				return;
			}
			$entry = $entry->as_entry();
		} else {
			$entry = GFEntryDetail::get_current_entry();
		}

		return $this->get_download_link( $entry );
	}

	private function generate_merge_url( $entry_id ) {
        // a fake nonce for temporary basic security
        $dnonce = substr( md5( ( $entry_id * GRAVITY_MERGE_PDFS_DNONCE_MULTIPLIER ) . GRAVITY_MERGE_PDFS_DNONCE_SECRET ), 0, 10 );
	
		return add_query_arg( [ 'gf_merge_pdfs' => $entry_id, 'nonce' => wp_create_nonce( "gf_merge_pdfs_$entry_id" ), 'dnonce' => $dnonce ], home_url() );
	}
}

GF_Fields::register( new GF_Merge_PDFs_Field() );
