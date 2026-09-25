<?php
/**
 * The screen: menu entry, assets, and the markup the JavaScript fills in.
 */

defined( 'ABSPATH' ) || exit;

class SWBM_Admin {

	const PAGE_SLUG = 'swbm-bulk-manager';

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * @return void
	 */
	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=product',
			__( 'Bulk Stock & Attributes', 'cartovum-bulk-product-manager' ),
			__( 'Bulk Stock & Attributes', 'cartovum-bulk-product-manager' ),
			SWBM_CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( 'product_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'swbm-admin', SWBM_URL . 'assets/admin.css', array( 'dashicons' ), SWBM_VERSION );
		wp_enqueue_script( 'swbm-admin', SWBM_URL . 'assets/admin.js', array(), SWBM_VERSION, true );

		wp_localize_script(
			'swbm-admin',
			'SWBM',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'swbm' ),
				'batchSize'     => SWBM_BATCH_SIZE,
				'maxConditions' => SWBM_Query::MAX_CONDITIONS,
				'attributes'    => SWBM_Attributes::global_attributes(),
				'localNames'    => SWBM_Attributes::local_attribute_names(),
				'localValues'   => SWBM_Attributes::local_attribute_values(),
			)
		);
	}

	/**
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( SWBM_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage products.', 'cartovum-bulk-product-manager' ) );
		}
		?>
		<div class="wrap swbm">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Bulk Stock & Attributes', 'cartovum-bulk-product-manager' ); ?></h1>
			<button type="button" class="page-title-action" id="swbm-view-details" aria-haspopup="dialog" aria-controls="swbm-details"><?php esc_html_e( 'View details', 'cartovum-bulk-product-manager' ); ?></button>
			<hr class="wp-header-end">
			<p class="swbm-intro">
				<?php esc_html_e( 'Find products, select them, then set their stock status or edit one attribute across all of them. Changes run in small batches and every run can be put back.', 'cartovum-bulk-product-manager' ); ?>
			</p>

			<div class="swbm-panel">
				<h2><?php esc_html_e( 'Find products', 'cartovum-bulk-product-manager' ); ?></h2>
				<div class="swbm-filters">
					<label>
						<span><?php esc_html_e( 'Search name', 'cartovum-bulk-product-manager' ); ?></span>
						<input type="search" id="swbm-search" placeholder="<?php esc_attr_e( 'Product name', 'cartovum-bulk-product-manager' ); ?>">
					</label>

					<label>
						<span><?php esc_html_e( 'Category', 'cartovum-bulk-product-manager' ); ?></span>
						<?php
						wp_dropdown_categories(
							array(
								'taxonomy'          => 'product_cat',
								'name'              => 'swbm-category',
								'id'                => 'swbm-category',
								'show_option_all'   => __( 'Any category', 'cartovum-bulk-product-manager' ),
								'hierarchical'      => true,
								'hide_empty'        => false,
								'orderby'           => 'name',
								'value_field'       => 'term_id',
							)
						);
						?>
					</label>

					<label>
						<span><?php esc_html_e( 'Stock status', 'cartovum-bulk-product-manager' ); ?></span>
						<select id="swbm-stock-filter">
							<option value=""><?php esc_html_e( 'Any', 'cartovum-bulk-product-manager' ); ?></option>
							<option value="instock"><?php esc_html_e( 'In stock', 'cartovum-bulk-product-manager' ); ?></option>
							<option value="outofstock"><?php esc_html_e( 'Out of stock', 'cartovum-bulk-product-manager' ); ?></option>
							<option value="onbackorder"><?php esc_html_e( 'On backorder', 'cartovum-bulk-product-manager' ); ?></option>
						</select>
					</label>

					<label>
						<span><?php esc_html_e( 'Published state', 'cartovum-bulk-product-manager' ); ?></span>
						<select id="swbm-post-status">
							<option value=""><?php esc_html_e( 'Any', 'cartovum-bulk-product-manager' ); ?></option>
							<option value="publish"><?php esc_html_e( 'Published', 'cartovum-bulk-product-manager' ); ?></option>
							<option value="draft"><?php esc_html_e( 'Draft', 'cartovum-bulk-product-manager' ); ?></option>
							<option value="pending"><?php esc_html_e( 'Pending', 'cartovum-bulk-product-manager' ); ?></option>
							<option value="private"><?php esc_html_e( 'Private', 'cartovum-bulk-product-manager' ); ?></option>
						</select>
					</label>

					<fieldset class="swbm-conditions" id="swbm-conditions">
						<legend><?php esc_html_e( 'Attribute conditions', 'cartovum-bulk-product-manager' ); ?></legend>
						<p class="swbm-conditions-hint"><?php esc_html_e( 'Products must match every condition.', 'cartovum-bulk-product-manager' ); ?></p>
						<div id="swbm-condition-list" class="swbm-condition-list">
							<div class="swbm-condition" role="group">
								<span class="swbm-condition-join" aria-hidden="true"><?php esc_html_e( 'AND', 'cartovum-bulk-product-manager' ); ?></span>
								<label>
									<span><?php esc_html_e( 'Attribute', 'cartovum-bulk-product-manager' ); ?></span>
									<select id="swbm-attribute-filter" class="swbm-cond-attribute"></select>
								</label>
								<label>
									<span><?php esc_html_e( 'Value', 'cartovum-bulk-product-manager' ); ?></span>
									<select id="swbm-attr-term" class="swbm-cond-value"></select>
								</label>
								<button type="button" class="button-link button-link-delete swbm-cond-remove" hidden><?php esc_html_e( 'Remove', 'cartovum-bulk-product-manager' ); ?></button>
							</div>
						</div>
						<p class="swbm-conditions-actions">
							<button type="button" class="button" id="swbm-add-condition"><?php esc_html_e( '+ Add condition', 'cartovum-bulk-product-manager' ); ?></button>
							<span class="description" id="swbm-condition-limit" hidden>
								<?php
								/* translators: %d: maximum number of conditions */
								echo esc_html( sprintf( __( 'Up to %d conditions.', 'cartovum-bulk-product-manager' ), SWBM_Query::MAX_CONDITIONS ) );
								?>
							</span>
						</p>
					</fieldset>

					<label class="swbm-wide">
						<span><?php esc_html_e( 'SKUs (one per line, or comma separated)', 'cartovum-bulk-product-manager' ); ?></span>
						<textarea id="swbm-skus" rows="2" placeholder="<?php esc_attr_e( 'AU56020QHN-15, MAZ125AA7019A-24', 'cartovum-bulk-product-manager' ); ?>"></textarea>
					</label>

					<label>
						<span><?php esc_html_e( 'Per page', 'cartovum-bulk-product-manager' ); ?></span>
						<select id="swbm-per-page">
							<option value="25">25</option>
							<option value="50" selected>50</option>
							<option value="100">100</option>
							<option value="200">200</option>
						</select>
					</label>
				</div>

				<p class="swbm-actions">
					<button type="button" class="button button-primary" id="swbm-find"><?php esc_html_e( 'Find products', 'cartovum-bulk-product-manager' ); ?></button>
					<button type="button" class="button" id="swbm-reset"><?php esc_html_e( 'Clear filters', 'cartovum-bulk-product-manager' ); ?></button>
				</p>
			</div>

			<div class="swbm-panel">
				<div class="swbm-selection-bar">
					<div>
						<button type="button" class="button" id="swbm-select-page"><?php esc_html_e( 'Select all on this page', 'cartovum-bulk-product-manager' ); ?></button>
						<button type="button" class="button" id="swbm-select-matching"><?php esc_html_e( 'Select all matching', 'cartovum-bulk-product-manager' ); ?></button>
						<button type="button" class="button" id="swbm-clear-selection"><?php esc_html_e( 'Clear selection', 'cartovum-bulk-product-manager' ); ?></button>
					</div>
					<div class="swbm-counts">
						<strong id="swbm-selected-count">0</strong> <?php esc_html_e( 'selected', 'cartovum-bulk-product-manager' ); ?>
						<span id="swbm-found-count"></span>
					</div>
				</div>

				<div id="swbm-table-wrap">
					<p class="swbm-empty"><?php esc_html_e( 'No search yet.', 'cartovum-bulk-product-manager' ); ?></p>
				</div>

				<div class="swbm-pagination">
					<button type="button" class="button" id="swbm-prev"><?php esc_html_e( 'Previous', 'cartovum-bulk-product-manager' ); ?></button>
					<span id="swbm-page-info"></span>
					<button type="button" class="button" id="swbm-next"><?php esc_html_e( 'Next', 'cartovum-bulk-product-manager' ); ?></button>
				</div>
			</div>

			<div class="swbm-columns">
				<div class="swbm-panel">
					<h2><?php esc_html_e( 'Set stock status', 'cartovum-bulk-product-manager' ); ?></h2>
					<p>
						<label for="swbm-stock-target"><?php esc_html_e( 'Set the selected products to', 'cartovum-bulk-product-manager' ); ?></label>
						<select id="swbm-stock-target">
							<option value="instock"><?php esc_html_e( 'In stock', 'cartovum-bulk-product-manager' ); ?></option>
							<option value="outofstock"><?php esc_html_e( 'Out of stock', 'cartovum-bulk-product-manager' ); ?></option>
							<option value="onbackorder"><?php esc_html_e( 'On backorder', 'cartovum-bulk-product-manager' ); ?></option>
						</select>
					</p>
					<p class="description">
						<?php esc_html_e( 'Only the stock status changes. Price, SKU, description, images, attributes and categories are checked afterwards and reported if anything else moved.', 'cartovum-bulk-product-manager' ); ?>
					</p>
					<p><button type="button" class="button button-primary" id="swbm-run-stock"><?php esc_html_e( 'Apply stock status', 'cartovum-bulk-product-manager' ); ?></button></p>
				</div>

				<div class="swbm-panel">
					<h2><?php esc_html_e( 'Edit an attribute', 'cartovum-bulk-product-manager' ); ?></h2>
					<p>
						<label for="swbm-op"><?php esc_html_e( 'Operation', 'cartovum-bulk-product-manager' ); ?></label>
						<select id="swbm-op">
							<option value="add_terms"><?php esc_html_e( 'Add values to an attribute', 'cartovum-bulk-product-manager' ); ?></option>
							<option value="remove_terms"><?php esc_html_e( 'Remove values from an attribute', 'cartovum-bulk-product-manager' ); ?></option>
							<option value="set_terms"><?php esc_html_e( 'Replace the values of an attribute', 'cartovum-bulk-product-manager' ); ?></option>
							<option value="remove_attribute"><?php esc_html_e( 'Remove the attribute', 'cartovum-bulk-product-manager' ); ?></option>
							<option value="set_local_value"><?php esc_html_e( 'Set the value of a local attribute', 'cartovum-bulk-product-manager' ); ?></option>
						</select>
					</p>
					<p>
						<label for="swbm-op-attribute"><?php esc_html_e( 'Attribute', 'cartovum-bulk-product-manager' ); ?></label>
						<select id="swbm-op-attribute"></select>
					</p>
					<p id="swbm-op-terms-row">
						<label for="swbm-op-terms"><?php esc_html_e( 'Values', 'cartovum-bulk-product-manager' ); ?></label>
						<select id="swbm-op-terms" multiple size="6"></select>
						<span class="description"><?php esc_html_e( 'Existing values only. This tool never creates a new attribute or a new value.', 'cartovum-bulk-product-manager' ); ?></span>
					</p>
					<p id="swbm-op-value-row" hidden>
						<label for="swbm-op-value"><?php esc_html_e( 'Value', 'cartovum-bulk-product-manager' ); ?></label>
						<input type="text" id="swbm-op-value" placeholder="<?php esc_attr_e( 'e.g. 112, or 112|114.3 for two values', 'cartovum-bulk-product-manager' ); ?>">
					</p>
					<p><button type="button" class="button button-primary" id="swbm-run-attributes"><?php esc_html_e( 'Apply attribute change', 'cartovum-bulk-product-manager' ); ?></button></p>
				</div>
			</div>

			<div class="swbm-panel" id="swbm-convert-panel" data-writes="<?php echo SWBM_Convert::writes_enabled() ? '1' : '0'; ?>">
				<h2><?php esc_html_e( 'Convert typed attributes to shared attributes', 'cartovum-bulk-product-manager' ); ?></h2>
				<?php if ( ! SWBM_Convert::writes_enabled() ) : ?>
					<div class="notice notice-info inline"><p><strong><?php esc_html_e( 'Dry run only.', 'cartovum-bulk-product-manager' ); ?></strong> <?php esc_html_e( 'Conversion is switched off on this site. The dry run reads the catalogue and changes nothing.', 'cartovum-bulk-product-manager' ); ?></p></div>
				<?php elseif ( SWBM_Convert::allowed_statuses() ) : ?>
					<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Staged conversion.', 'cartovum-bulk-product-manager' ); ?></strong>
					<?php
					/* translators: %s: product statuses */
					echo esc_html( sprintf( __( 'Only products with status %s can be converted at this stage. Anything else is skipped and left unchanged.', 'cartovum-bulk-product-manager' ), implode( ', ', SWBM_Convert::allowed_statuses() ) ) );
					if ( SWBM_Convert::allowed_ids() ) {
						echo ' ';
						/* translators: %s: product IDs */
						echo esc_html( sprintf( __( 'Also approved individually: products %s.', 'cartovum-bulk-product-manager' ), implode( ', ', SWBM_Convert::allowed_ids() ) ) );
					}
					?>
					</p></div>
				<?php endif; ?>
				<p class="description">
					<?php esc_html_e( 'Moves a typed value, such as Rim Size 18, onto the shared attribute of the same name, such as Rim Size 18". A value only moves when it matches one shared value exactly or has an approved mapping. Anything else is reported and the product is left exactly as it is.', 'cartovum-bulk-product-manager' ); ?>
				</p>
				<p class="swbm-actions">
					<button type="button" class="button" id="swbm-convert-dryrun"><?php esc_html_e( 'Run dry run (changes nothing)', 'cartovum-bulk-product-manager' ); ?></button>
					<button type="button" class="button" id="swbm-convert-download" disabled><?php esc_html_e( 'Download dry-run report (CSV)', 'cartovum-bulk-product-manager' ); ?></button>
				</p>
				<div id="swbm-convert-summary"></div>
				<p class="swbm-actions">
					<button type="button" class="button button-primary" id="swbm-convert-run" disabled><?php esc_html_e( 'Convert selected products', 'cartovum-bulk-product-manager' ); ?></button>
					<span class="description"><?php esc_html_e( 'Only selected products that were ready in the latest dry run are converted. A run stops at the first product that fails its check.', 'cartovum-bulk-product-manager' ); ?></span>
				</p>
			</div>

			<div class="swbm-panel" id="swbm-run-panel" hidden>
				<h2><?php esc_html_e( 'Progress', 'cartovum-bulk-product-manager' ); ?></h2>
				<div class="swbm-progress"><div class="swbm-progress-bar" id="swbm-progress-bar"></div></div>
				<p id="swbm-progress-text"></p>
				<p>
					<button type="button" class="button" id="swbm-stop" hidden><?php esc_html_e( 'Stop after this batch', 'cartovum-bulk-product-manager' ); ?></button>
				</p>
				<div id="swbm-results"></div>
			</div>

			<div class="swbm-panel">
				<h2><?php esc_html_e( 'Recent runs', 'cartovum-bulk-product-manager' ); ?></h2>
				<p><button type="button" class="button" id="swbm-refresh-jobs"><?php esc_html_e( 'Refresh', 'cartovum-bulk-product-manager' ); ?></button></p>
				<div id="swbm-jobs"></div>
			</div>

			<?php self::render_details(); ?>
		</div>
		<?php
	}

	/**
	 * Facts about the plugin, read from its own header and readme so nothing here goes stale.
	 *
	 * @return array
	 */
	private static function plugin_info() {
		$info = get_file_data(
			SWBM_FILE,
			array(
				'name'         => 'Plugin Name',
				'version'      => 'Version',
				'description'  => 'Description',
				'author'       => 'Author',
				'author_uri'   => 'Author URI',
				'plugin_uri'   => 'Plugin URI',
				'requires_wp'  => 'Requires at least',
				'requires_php' => 'Requires PHP',
				'wc_requires'  => 'WC requires at least',
				'wc_tested'    => 'WC tested up to',
			)
		);

		$readme             = SWBM_PATH . 'readme.txt';
		$readme_headers     = file_exists( $readme ) ? get_file_data( $readme, array( 'tested_wp' => 'Tested up to' ) ) : array( 'tested_wp' => '' );
		$info['tested_wp']  = $readme_headers['tested_wp'];
		$info['readme_url'] = file_exists( $readme ) ? SWBM_URL . 'readme.txt' : '';

		return $info;
	}

	/**
	 * The "View details" panel: what this plugin is, what it does, and what it runs on.
	 *
	 * @return void
	 */
	private static function render_details() {
		$info     = self::plugin_info();
		$features = array(
			__( 'Find products by name, a list of SKUs, category, stock status and published state', 'cartovum-bulk-product-manager' ),
			__( 'Filter by several attribute conditions at once; a product must match them all', 'cartovum-bulk-product-manager' ),
			__( 'Select products page by page, or every matching product in one click', 'cartovum-bulk-product-manager' ),
			__( 'Bulk stock status: in stock, out of stock or on backorder', 'cartovum-bulk-product-manager' ),
			__( 'Bulk attribute edits: add, remove or replace values, remove an attribute, or set a typed value', 'cartovum-bulk-product-manager' ),
			__( 'Convert typed attributes to shared attributes, with a dry run and a CSV report', 'cartovum-bulk-product-manager' ),
			__( 'Small batches with live progress and a result for every product', 'cartovum-bulk-product-manager' ),
			__( 'A log of recent runs, any of which can be put back', 'cartovum-bulk-product-manager' ),
		);
		$compat   = array(
			array( __( 'WordPress', 'cartovum-bulk-product-manager' ), $info['requires_wp'], $info['tested_wp'], get_bloginfo( 'version' ) ),
			array( __( 'WooCommerce', 'cartovum-bulk-product-manager' ), $info['wc_requires'], $info['wc_tested'], defined( 'WC_VERSION' ) ? WC_VERSION : '' ),
			array( __( 'PHP', 'cartovum-bulk-product-manager' ), $info['requires_php'], '', PHP_VERSION ),
		);
		$website  = $info['author_uri'] ? $info['author_uri'] : $info['plugin_uri'];
		$support  = defined( 'SWBM_SUPPORT_URL' ) && SWBM_SUPPORT_URL ? SWBM_SUPPORT_URL : $website;
		?>
		<dialog id="swbm-details" class="swbm-details" aria-labelledby="swbm-details-title">
			<div class="swbm-details-head">
				<div class="swbm-details-title">
					<h2 id="swbm-details-title"><?php echo esc_html( $info['name'] ); ?></h2>
					<?php if ( $info['version'] ) : ?>
						<span class="swbm-details-version">
							<?php
							/* translators: %s: plugin version */
							echo esc_html( sprintf( __( 'Version %s', 'cartovum-bulk-product-manager' ), $info['version'] ) );
							?>
						</span>
					<?php endif; ?>
				</div>
				<button type="button" class="swbm-details-close" data-swbm-close aria-label="<?php esc_attr_e( 'Close details', 'cartovum-bulk-product-manager' ); ?>">
					<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
				</button>
			</div>

			<div class="swbm-details-body">
				<?php if ( $info['description'] ) : ?>
					<p class="swbm-details-lede"><?php echo esc_html( $info['description'] ); ?></p>
				<?php endif; ?>

				<dl class="swbm-details-meta">
					<?php if ( $info['author'] ) : ?>
						<dt><?php esc_html_e( 'Developed by', 'cartovum-bulk-product-manager' ); ?></dt>
						<dd><?php echo esc_html( $info['author'] ); ?></dd>
					<?php endif; ?>
					<?php if ( $website ) : ?>
						<dt><?php esc_html_e( 'Website', 'cartovum-bulk-product-manager' ); ?></dt>
						<dd><a href="<?php echo esc_url( $website ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( wp_parse_url( $website, PHP_URL_HOST ) ? wp_parse_url( $website, PHP_URL_HOST ) : $website ); ?></a></dd>
					<?php endif; ?>
				</dl>

				<h3><?php esc_html_e( 'Features', 'cartovum-bulk-product-manager' ); ?></h3>
				<ul class="swbm-details-features">
					<?php foreach ( $features as $feature ) : ?>
						<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php echo esc_html( $feature ); ?></li>
					<?php endforeach; ?>
				</ul>

				<h3><?php esc_html_e( 'Compatibility', 'cartovum-bulk-product-manager' ); ?></h3>
				<div class="swbm-details-table">
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"></th>
								<th scope="col"><?php esc_html_e( 'Requires', 'cartovum-bulk-product-manager' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Tested up to', 'cartovum-bulk-product-manager' ); ?></th>
								<th scope="col"><?php esc_html_e( 'This site', 'cartovum-bulk-product-manager' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $compat as $row ) : ?>
								<tr>
									<th scope="row"><?php echo esc_html( $row[0] ); ?></th>
									<td><?php echo esc_html( $row[1] ? $row[1] : '—' ); ?></td>
									<td><?php echo esc_html( $row[2] ? $row[2] : '—' ); ?></td>
									<td><?php echo esc_html( $row[3] ? $row[3] : '—' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<?php if ( $info['readme_url'] || $support ) : ?>
					<h3><?php esc_html_e( 'Documentation and support', 'cartovum-bulk-product-manager' ); ?></h3>
					<p class="swbm-details-links">
						<?php if ( $info['readme_url'] ) : ?>
							<a class="button" href="<?php echo esc_url( $info['readme_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Plugin guide (readme)', 'cartovum-bulk-product-manager' ); ?></a>
						<?php endif; ?>
						<?php if ( $support ) : ?>
							<a class="button" href="<?php echo esc_url( $support ); ?>" target="_blank" rel="noopener noreferrer">
								<?php
								/* translators: %s: developer name */
								echo esc_html( sprintf( __( 'Contact %s', 'cartovum-bulk-product-manager' ), $info['author'] ? $info['author'] : __( 'the developer', 'cartovum-bulk-product-manager' ) ) );
								?>
							</a>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</div>

			<div class="swbm-details-foot">
				<button type="button" class="button button-primary" data-swbm-close><?php esc_html_e( 'Close', 'cartovum-bulk-product-manager' ); ?></button>
			</div>
		</dialog>
		<?php
	}
}
