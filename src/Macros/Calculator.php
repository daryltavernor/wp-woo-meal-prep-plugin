<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Macros;

use FastNutrition\MealPrep\PostTypes\Ingredient;

final class Calculator {

	public const EMPTY = [
		'kcal'      => 0.0,
		'protein_g' => 0.0,
		'carbs_g'   => 0.0,
		'fat_g'     => 0.0,
	];

	public function register(): void {
		add_action( 'woocommerce_email_after_order_table', [ $this, 'email_totals' ], 10, 4 );
	}

	public static function macros_for_selection( int $product_id, array $selection ): array {
		$total = self::EMPTY;
		if ( 'set' === ( $selection['mode'] ?? '' ) && ! empty( $selection['set_meal_id'] ) ) {
			return self::add( $total, Ingredient::get_macros( (int) $selection['set_meal_id'] ) );
		}
		if ( ! empty( $selection['protein_id'] ) ) {
			$total = self::add( $total, Ingredient::get_macros( (int) $selection['protein_id'] ) );
		}
		if ( ! empty( $selection['carb_id'] ) ) {
			$total = self::add( $total, Ingredient::get_macros( (int) $selection['carb_id'] ) );
		}
		foreach ( ( $selection['greens_ids'] ?? [] ) as $id ) {
			$total = self::add( $total, Ingredient::get_macros( (int) $id ) );
		}
		return $total;
	}

	public static function add( array $a, array $b ): array {
		return [
			'kcal'      => (float) ( $a['kcal'] ?? 0 ) + (float) ( $b['kcal'] ?? 0 ),
			'protein_g' => (float) ( $a['protein_g'] ?? 0 ) + (float) ( $b['protein_g'] ?? 0 ),
			'carbs_g'   => (float) ( $a['carbs_g'] ?? 0 ) + (float) ( $b['carbs_g'] ?? 0 ),
			'fat_g'     => (float) ( $a['fat_g'] ?? 0 ) + (float) ( $b['fat_g'] ?? 0 ),
		];
	}

	public static function scale( array $macros, float $factor ): array {
		return [
			'kcal'      => (float) ( $macros['kcal'] ?? 0 ) * $factor,
			'protein_g' => (float) ( $macros['protein_g'] ?? 0 ) * $factor,
			'carbs_g'   => (float) ( $macros['carbs_g'] ?? 0 ) * $factor,
			'fat_g'     => (float) ( $macros['fat_g'] ?? 0 ) * $factor,
		];
	}

	public function email_totals( $order, bool $sent_to_admin, bool $plain_text, $email ): void {
		if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
			return;
		}
		$total = self::EMPTY;
		foreach ( $order->get_items() as $item ) {
			$snapshot = $item->get_meta( '_fn_macros_snapshot', true );
			if ( is_array( $snapshot ) ) {
				$total = self::add( $total, self::scale( $snapshot, (float) $item->get_quantity() ) );
			}
		}
		if ( $total === self::EMPTY ) {
			return;
		}
		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Order macros', 'fastnutrition-mealprep' ) . ":\n";
			printf( "%s kcal, P %sg, C %sg, F %sg\n",
				number_format( $total['kcal'], 0 ),
				number_format( $total['protein_g'], 1 ),
				number_format( $total['carbs_g'], 1 ),
				number_format( $total['fat_g'], 1 )
			);
			return;
		}
		echo '<h3>' . esc_html__( 'Order macros', 'fastnutrition-mealprep' ) . '</h3>';
		echo '<p style="font-size:14px">' . sprintf(
			/* translators: kcal, protein, carbs, fat */
			esc_html__( '%1$s kcal · Protein %2$sg · Carbs %3$sg · Fat %4$sg', 'fastnutrition-mealprep' ),
			esc_html( number_format( $total['kcal'], 0 ) ),
			esc_html( number_format( $total['protein_g'], 1 ) ),
			esc_html( number_format( $total['carbs_g'], 1 ) ),
			esc_html( number_format( $total['fat_g'], 1 ) )
		) . '</p>';
	}
}
