import { __ } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';

export default function Edit() {
	return (
		<p { ...useBlockProps() }>
			{ __( 'Slot Picker — rendered during checkout.', 'fastnutrition-mealprep' ) }
		</p>
	);
}
