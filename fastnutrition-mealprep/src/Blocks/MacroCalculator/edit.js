import { __ } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';

export default function Edit( { attributes, setAttributes } ) {
	const { allowCustom, showTargets } = attributes;
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Calculator settings', 'fastnutrition-mealprep' ) }>
					<ToggleControl
						label={ __( 'Allow custom ingredients', 'fastnutrition-mealprep' ) }
						checked={ allowCustom }
						onChange={ ( v ) => setAttributes( { allowCustom: v } ) }
					/>
					<ToggleControl
						label={ __( 'Show daily targets', 'fastnutrition-mealprep' ) }
						checked={ showTargets }
						onChange={ ( v ) => setAttributes( { showTargets: v } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<p { ...useBlockProps() }>
				{ __( 'Macro Calculator — rendered on the front-end.', 'fastnutrition-mealprep' ) }
			</p>
		</>
	);
}
