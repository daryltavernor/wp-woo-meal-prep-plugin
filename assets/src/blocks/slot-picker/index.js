import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';

registerBlockType( metadata.name, {
	edit() {
		const blockProps = useBlockProps();
		return (
			<div { ...blockProps }>
				<strong>{ __( 'Slot picker', 'fastnutrition-mealprep' ) }</strong> — { __( 'renders delivery/collection options at checkout.', 'fastnutrition-mealprep' ) }
			</div>
		);
	},
	save() {
		return <div { ...useBlockProps.save() } data-fn-slot-picker="1"></div>;
	},
} );
