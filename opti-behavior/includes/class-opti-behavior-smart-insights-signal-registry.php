<?php
/**
 * Smart Insights Signal Registry Class
 *
 * Keeps deterministic signal registration extensible for Free and Pro.
 *
 * @package opti-behavior
 * @since   1.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smart Insights Signal Registry Class.
 *
 * Free registers the built-in signal catalog foundation. Pro and future modules
 * can add signals through hooks without replacing the generator.
 *
 * @since 1.3.3
 */
class Opti_Behavior_Smart_Insights_Signal_Registry {

	/**
	 * Registered signals keyed by signal ID.
	 *
	 * @var array
	 */
	private $signals = array();

	/**
	 * Constructor.
	 *
	 * @param array $signals Optional initial signals.
	 */
	public function __construct( $signals = array() ) {
		foreach ( $signals as $signal ) {
			$this->register( $signal );
		}
	}

	/**
	 * Build a registry with Free default signals.
	 *
	 * @return self
	 */
	public static function with_default_signals() {
		$registry = new self();

		if ( class_exists( 'Opti_Behavior_Smart_Insights_Signal_High_Traffic_Low_Engagement' ) ) {
			$registry->register( new Opti_Behavior_Smart_Insights_Signal_High_Traffic_Low_Engagement() );
		}

		$free_signal_classes = array(
			'Opti_Behavior_Smart_Insights_Signal_High_Exit_Rate_Page',
			'Opti_Behavior_Smart_Insights_Signal_Low_Scroll_Depth_Page',
			'Opti_Behavior_Smart_Insights_Signal_Basic_Bounce_Alert',
			'Opti_Behavior_Smart_Insights_Signal_Traffic_Spike_Observation',
			'Opti_Behavior_Smart_Insights_Signal_Basic_Mobile_Bounce_Warning',
		);

		foreach ( $free_signal_classes as $signal_class ) {
			if ( class_exists( $signal_class ) ) {
				$registry->register( new $signal_class() );
			}
		}

		/**
		 * Allow Free and Pro modules to register signal objects.
		 *
		 * Signals should expose either `evaluate_page()` for page metrics or a
		 * future entity-specific evaluator method used by later phases.
		 *
		 * @param Opti_Behavior_Smart_Insights_Signal_Registry $registry Registry.
		 */
		do_action( 'opti_behavior_smart_insights_register_signals', $registry );

		return $registry;
	}

	/**
	 * Register a signal object.
	 *
	 * @param object $signal Signal evaluator.
	 * @return bool
	 */
	public function register( $signal ) {
		if ( ! is_object( $signal ) ) {
			return false;
		}

		$signal_id = $this->get_signal_id( $signal );
		if ( '' === $signal_id ) {
			return false;
		}

		$this->signals[ $signal_id ] = $signal;

		return true;
	}

	/**
	 * Return all registered signals.
	 *
	 * @param array $context Generation context.
	 * @return array
	 */
	public function all( $context = array() ) {
		$signals = array_values( $this->signals );

		return apply_filters( 'opti_behavior_smart_insights_registered_signals', $signals, $this, $context );
	}

	/**
	 * Return signals that can evaluate a specific entity type.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $context     Generation context.
	 * @return array
	 */
	public function for_entity_type( $entity_type, $context = array() ) {
		$entity_type = sanitize_key( $entity_type );
		$signals     = array_filter(
			$this->all( $context ),
			function( $signal ) use ( $entity_type ) {
				if ( method_exists( $signal, 'get_definition' ) ) {
					$definition = $signal->get_definition();
					return isset( $definition['entity_type'] ) && sanitize_key( $definition['entity_type'] ) === $entity_type;
				}

				if ( 'page' === $entity_type && method_exists( $signal, 'evaluate_page' ) ) {
					return true;
				}

				return false;
			}
		);

		return array_values( $signals );
	}

	/**
	 * Return registered signal IDs, optionally limited by entity type.
	 *
	 * @param string $entity_type Optional entity type.
	 * @param array  $context     Generation context.
	 * @return array
	 */
	public function get_signal_ids( $entity_type = '', $context = array() ) {
		$signals = '' === $entity_type ? $this->all( $context ) : $this->for_entity_type( $entity_type, $context );
		$ids     = array();

		foreach ( $signals as $signal ) {
			$signal_id = $this->get_signal_id( $signal );
			if ( '' !== $signal_id ) {
				$ids[] = $signal_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Resolve a stable signal ID from an evaluator object.
	 *
	 * @param object $signal Signal evaluator.
	 * @return string
	 */
	private function get_signal_id( $signal ) {
		if ( method_exists( $signal, 'get_definition' ) ) {
			$definition = $signal->get_definition();
			if ( ! empty( $definition['signal_id'] ) ) {
				return sanitize_key( $definition['signal_id'] );
			}
		}

		if ( defined( get_class( $signal ) . '::SIGNAL_ID' ) ) {
			return sanitize_key( constant( get_class( $signal ) . '::SIGNAL_ID' ) );
		}

		return '';
	}
}
