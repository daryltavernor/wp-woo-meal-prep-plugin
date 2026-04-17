import { __ } from '@wordpress/i18n';
import { useBlockProps, InnerBlocks } from '@wordpress/block-editor';

export default function Edit() {
	return (
		<div { ...useBlockProps() }>
			<p style={ { fontWeight: 'bold' } }>
				{ __( 'Fast Nutrition — Multi-Step Checkout', 'fastnutrition-mealprep' ) }
			</p>
			<InnerBlocks />
		</div>
	);
}
