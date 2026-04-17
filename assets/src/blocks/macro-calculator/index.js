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
					{ __( 'Macro Calculator placeholder. Renders on the front end.', 'fastnutrition-mealprep' ) }
				</p>
			</div>
		);
	},
	save() {
		return <div data-fn-macro-calc="1"></div>;
	},
} );
