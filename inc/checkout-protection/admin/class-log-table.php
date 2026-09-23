<?php
/**
 * Checkout Protection — list table for the Blocked attempts tab.
 *
 * WordPress admin tables must extend WP_List_Table, so this is the module's
 * one class. Loaded only when the Blocked attempts tab is opened.
 *
 * @package GPH_Core
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class GPH_CP_Log_Table extends WP_List_Table {

	const PER_PAGE = 20;

	/** @var string Current search. */
	private $search = '';

	public function __construct() {
		parent::__construct( array(
			'singular' => 'attempt',
			'plural'   => 'attempts',
			'ajax'     => false,
		) );
	}

	public function get_columns() {
		return array(
			'ref'      => __( 'Reference', 'gph-core' ),
			'customer' => __( 'Customer', 'gph-core' ),
			'address'  => __( 'Billing address', 'gph-core' ),
			'items'    => __( 'Cart', 'gph-core' ),
			'reason'   => __( 'Why stopped', 'gph-core' ),
			'ip'       => __( 'Connection', 'gph-core' ),
		);
	}

	public function prepare_items() {
		global $wpdb;

		$this->search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$table        = gph_cp_table();
		$where        = gph_cp_log_where( $this->search );
		$page         = $this->get_pagenum();

		if ( ! gph_cp_log_ready() ) {
			$this->items           = array();
			$this->_column_headers = array( $this->get_columns(), array(), array() );
			return;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared.
		$total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		$this->items = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
			self::PER_PAGE,
			( $page - 1 ) * self::PER_PAGE
		) );
		// phpcs:enable

		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->set_pagination_args( array(
			'total_items' => $total,
			'per_page'    => self::PER_PAGE,
		) );
	}

	public function no_items() {
		echo esc_html( '' !== $this->search ? __( 'No blocked attempts match your search.', 'gph-core' ) : __( 'Nothing blocked yet.', 'gph-core' ) );
	}

	protected function column_ref( $r ) {
		return '<code>' . esc_html( $r->ref ) . '</code>'
			. '<span class="gph-cp-muted">' . esc_html( get_date_from_gmt( $r->created_at, 'M j, Y g:i a' ) ) . '</span>';
	}

	protected function column_customer( $r ) {
		$name = trim( $r->first_name . ' ' . $r->last_name );
		$out  = '<strong>' . esc_html( '' !== $name ? $name : '—' ) . '</strong>';

		if ( $r->email ) {
			$out .= '<span><a href="mailto:' . esc_attr( $r->email ) . '">' . esc_html( $r->email ) . '</a></span>';
		}
		if ( $r->phone ) {
			$out .= '<span><a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $r->phone ) ) . '">' . esc_html( $r->phone ) . '</a></span>';
		}

		if ( $r->email && is_email( $r->email ) ) {
			$blocked = gph_cp_is_blocked_email( $r->email );
			$url     = wp_nonce_url(
				add_query_arg( array(
					'action' => 'gph_cp_toggle_block',
					'email'  => $r->email,
					'do'     => $blocked ? 'unblock' : 'block',
					's'      => $this->search,
				), admin_url( 'admin-post.php' ) ),
				'gph_cp_toggle_block'
			);

			if ( $blocked ) {
				$out .= ' <span class="gph-cp-pill is-bad">' . esc_html__( 'Blocked', 'gph-core' ) . '</span>';
			}
			$out .= $this->row_actions( array(
				'toggle' => '<a href="' . esc_url( $url ) . '">' . esc_html( $blocked ? __( 'Unblock email', 'gph-core' ) : __( 'Block email', 'gph-core' ) ) . '</a>',
			) );
		}

		return $out;
	}

	protected function column_address( $r ) {
		return '' !== $r->address ? nl2br( esc_html( $r->address ) ) : '<span class="gph-cp-muted">—</span>';
	}

	protected function column_items( $r ) {
		$out = '' !== $r->items ? nl2br( esc_html( $r->items ) ) : '<span class="gph-cp-muted">—</span>';

		if ( (float) $r->total > 0 ) {
			$out .= '<strong class="gph-cp-block">' . wp_kses_post( wc_price( $r->total ) ) . '</strong>';
		}

		if ( $r->order_id ) {
			$order = wc_get_order( $r->order_id );
			if ( $order ) {
				/* translators: %d: order number */
				$out .= '<a class="gph-cp-block" href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html( sprintf( __( 'Order #%d', 'gph-core' ), $order->get_id() ) ) . '</a>';
			}
		}

		return $out;
	}

	protected function column_reason( $r ) {
		return esc_html( $r->reason ) . '<span class="gph-cp-muted">' . esc_html( $r->source ) . '</span>';
	}

	protected function column_ip( $r ) {
		return esc_html( $r->ip ? $r->ip : '—' )
			. '<span class="gph-cp-muted" title="' . esc_attr( $r->user_agent ) . '">' . esc_html( wp_trim_words( $r->user_agent, 5, '…' ) ) . '</span>';
	}
}
