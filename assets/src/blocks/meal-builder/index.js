import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';

registerBlockType( metadata.name, {
	edit() {
		const blockProps = useBlockProps();
		return (
			<div { ...blockProps }>
				<p style={ { padding: '1em', border: '1px dashed #999' } }>
					{ __( 'Meal Builder — rendered on the product page.', 'fastnutrition-mealprep' ) }
				</p>
			</div>
		);
	},
	save() {
		return null;
	},
} );
