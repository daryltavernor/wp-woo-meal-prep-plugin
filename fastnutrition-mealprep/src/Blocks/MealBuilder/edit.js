/**
 * Gutenberg editor preview for the Meal Builder block.
 * Runtime behaviour lives in assets/src/js/meal-builder/*.
 */
import { __ } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';

export default function Edit() {
	return (
		<p { ...useBlockProps() }>
			{ __(
				'Meal Builder — renders on the front-end of meal products.',
				'fastnutrition-mealprep'
			) }
		</p>
	);
}
