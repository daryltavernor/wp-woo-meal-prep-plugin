import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InnerBlocks } from '@wordpress/block-editor';
import metadata from './block.json';

const TEMPLATE = [
	[ 'woocommerce/checkout', {}, [] ],
];

registerBlockType( metadata.name, {
	edit() {
		const blockProps = useBlockProps();
		return (
			<div { ...blockProps }>
				<InnerBlocks template={ TEMPLATE } templateLock={ false } />
			</div>
		);
	},
	save() {
		return (
			<div { ...useBlockProps.save() } data-fn-multistep="1">
				<InnerBlocks.Content />
			</div>
		);
	},
} );
