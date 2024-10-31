<?php
/*
Plugin Name: Personyze System Integration
Plugin URI: https://www.personyze.com/
Description: Integrates the site with Personyze personalization platform.
Author: Personyze
Version: 0.20
Author URI: https://www.personyze.com/
*/

namespace Personyze;

use WP_REST_Request, WP_Error;

const VERSION = '0.20';
const IS_DEV = false;
const POSTS_ON_PAGE = 100;

function get_remote_addr()
{	static $ip = null;
	if ($ip === null)
	{	$ip = '';
		// HTTP_X_FORWARDED_FOR can contain several ','-separated addresses, and can contain string 'unknown'
		$try = ($_SERVER['REMOTE_ADDR'] ?? '') . ',' . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '') . ',' . ($_SERVER['HTTP_CLIENT_IP'] ?? '');
		foreach (array_reverse(explode(',', $try)) as $one) // last is likely valid
		{	$one = trim($one);
			if (strlen($one)>0 and $one!='unknown')
			{	$ip = $one;
				break;
			}
		}
	}
	return $ip;
}

if (function_exists('add_action'))
{	/*	1. Install REST API endpoint.

		a. Personyze.com will connect to `personyze/v1/config` and send auto configuration variables..
		b. Personyze.com will connect to `personyze/v1/content` and ask to substitute shortcodes in an HTML string.
	 */
	add_action
	(	'rest_api_init',
		function()
		{	// Function to check that the current user has permission to query REST
			$permission_callback = function()
			{	if (!IS_DEV)
				{	$accept_from = gethostbyname("personyze.com");
					if (get_remote_addr() != $accept_from)
					{	return false;
					}
				}
				return current_user_can('manage_options');
			};

			// Get/set "account_id" and "tracking_domains".
			register_rest_route
			(	'personyze/v1',
				'/config',
				[	// Get current values for "account_id" and "tracking_domains", and some additional info.
					[	'methods' => 'GET',
						'permission_callback' => $permission_callback,
						'callback' => function()
						{	global $wp_version;

							$account_id = (int)get_option('personyze_account_id');
							$tracking_domains = (string)get_option('personyze_tracking_domains');
							$track_add_to_cart = (bool)get_option('personyze_track_add_to_cart');
							$track_purchase = (bool)get_option('personyze_track_purchase');
							return
							[	'account_id' => $account_id,
								'tracking_domains' => $tracking_domains,
								'track_add_to_cart' => $track_add_to_cart,
								'track_purchase' => $track_purchase,
								'version' => VERSION,
								'wp_version' => $wp_version,
								'php_version' => phpversion(),
							];
						},
					],
					// Set new values for "account_id" and "tracking_domains".
					[	'methods' => 'POST',
						'permission_callback' => $permission_callback,
						'callback' => function(WP_REST_Request $request)
						{	$account_id = (int)$request->get_param('account_id');
							$tracking_domains = (string)$request->get_param('tracking_domains');
							$track_add_to_cart = (bool)(int)$request->get_param('track_add_to_cart');
							$track_purchase = (bool)(int)$request->get_param('track_purchase');
							if ($account_id <= 0)
							{	return new WP_Error('rest_custom_error', 'Invalid account_id', ['status' => 500]);
							}
							if (strlen($tracking_domains) == 0)
							{	return new WP_Error('rest_custom_error', 'Invalid tracking_domains', ['status' => 500]);
							}
							update_option('personyze_account_id', $account_id);
							update_option('personyze_tracking_domains', $tracking_domains);
							update_option('personyze_track_add_to_cart', $track_add_to_cart);
							update_option('personyze_track_purchase', $track_purchase);
							return 'OK';
						},
					]
				]
			);
			
			// Substitute shortcodes.
			register_rest_route
			(	'personyze/v1',
				'/content',
				[	'methods' => 'POST',
					'permission_callback' => $permission_callback,
					'callback' => function(WP_REST_Request $request)
					{	$result = [];
						foreach ($request->get_body_params() as $k => $v)
						{	$result[$k] = do_shortcode($v);
						}
						return $result;
					},
				]
			);

			// Function that reads posts as JSON, and exits PHP
			$get_feed_callback = function(bool $is_article)
			{	return function(WP_REST_Request $request) use($is_article)
				{	global $wpdb;
					
					$id_from = (int)$request->get_param('id-from');
					$limit = (int)$request->get_param('limit');
					if ($limit <= 0)
					{	$limit = PHP_INT_MAX;
					}

					$post_type_in_sql = $is_article ? "'page', 'post'" : "'product'";
					$category_field = $is_article ? "category" : "product_cat";
					$tag_field = $is_article ? "post_tag" : "product_tag";
					if ($is_article)
					{	$more_joins =
						"	LEFT JOIN {$wpdb->users} AS u ON p.post_author = u.id
						";
						$more_columns =
						"	u.user_nicename AS author,
							p.post_type AS type,
						";
					}
					else
					{	$more_joins =
						"	LEFT JOIN {$wpdb->postmeta} AS m ON p.id = m.post_id
						";
						$more_columns =
						"	Max(CASE m.meta_key WHEN '_regular_price' THEN meta_value ELSE NULL END) AS price,
							Max(CASE m.meta_key WHEN '_sale_price' THEN meta_value ELSE NULL END) AS sale_price,
							Max(CASE m.meta_key WHEN '_stock_status' THEN CASE meta_value WHEN 'instock' THEN 'yes' ELSE 'no' END ELSE NULL END) AS is_in_stock,
							Max(CASE m.meta_key WHEN '_stock' THEN meta_value ELSE NULL END) AS inventory,
						";
					}
					
					// I'm going to output large resultset. No need to store it in memory.
					while (ob_get_level() > 0)
					{	ob_end_flush();
					}

					// Echo the resultset and exit.
					echo '[';
					$delim = '';
					while (true)
					{	$use_limit = min(POSTS_ON_PAGE, $limit);
						$posts = $wpdb->get_results
						(	$wpdb->prepare
							(	"	SELECT
										p.id AS id,
										$more_columns
										p.post_title AS title,
										p.post_excerpt AS description,
										p.post_modified_gmt AS publish_date,
										p.post_name AS name,
										p.comment_count AS comment_count,
										Coalesce(Group_concat(DISTINCT CASE tt.taxonomy WHEN '$category_field' THEN t.name ELSE NULL END), '') AS categories,
										Coalesce(Group_concat(DISTINCT CASE tt.taxonomy WHEN '$tag_field' THEN t.name ELSE NULL END), '') AS interests
									FROM {$wpdb->posts} AS p
									$more_joins
									LEFT JOIN {$wpdb->term_relationships} AS ptt ON p.id = ptt.object_id
									LEFT JOIN {$wpdb->term_taxonomy} AS tt ON ptt.term_taxonomy_id = tt.term_taxonomy_id
									LEFT JOIN {$wpdb->terms} AS t ON tt.term_id = t.term_id
									WHERE p.id >= $id_from
									AND p.post_password = ''
									AND p.post_type IN ($post_type_in_sql)
									AND p.post_status = 'publish'
									GROUP BY p.id
									ORDER BY p.id
									LIMIT %d
								",
								$use_limit
							)
						);
						if ($wpdb->last_error !== '')
						{	return new WP_Error('rest_custom_error', $wpdb->last_error, ['status' => 500]);
						}
						foreach ($posts as $post)
						{	$post->content_url = (string)get_permalink($post->id);
							$post->thumbnail = (string)get_the_post_thumbnail_url($post->id);

							echo $delim, json_encode($post);
							$delim = ',';

							if (--$limit <= 0)
							{	break 2;
							}
						}
						if (count($posts) < $use_limit)
						{	break;
						}
						$id_from = $posts[$use_limit-1]->id + 1;
					}
					echo ']';
					exit;
				};
			};

			// Get sitemap.
			register_rest_route
			(	'personyze/v1',
				'/sitemap',
				[	'methods' => 'GET',
					'permission_callback' => $permission_callback,
					'callback' => $get_feed_callback(true),
				]
			);

			// Get products feed.
			register_rest_route
			(	'personyze/v1',
				'/products',
				[	'methods' => 'GET',
					'permission_callback' => $permission_callback,
					'callback' => $get_feed_callback(false),
				]
			);

			// Get stats.
			register_rest_route
			(	'personyze/v1',
				'/stats',
				[	'methods' => 'GET',
					'permission_callback' => $permission_callback,
					'callback' => function()
					{	global $wpdb;

						$result = $wpdb->get_results
						(	$wpdb->prepare
							(	"	SELECT
										Count(*) AS n_posts,
										Sum(p.post_type = 'page') AS n_pages,
										Sum(p.post_type = 'product') AS n_products
									FROM {$wpdb->posts} AS p
									WHERE p.post_password = ''
									AND p.post_status = 'publish'
								",
								POSTS_ON_PAGE
							)
						);
						if ($wpdb->last_error!=='' or !$result)
						{	return new WP_Error('rest_custom_error', $wpdb->last_error, ['status' => 500]);
						}

						return $result[0];
					},
				]
			);
		}
	);

	/*	2. Inject Personyze tracking code to the page.
	 */
	add_action
	(	'init',
		function()
		{	$update_cart = '';

			$track_add_to_cart = (bool)get_option('personyze_track_add_to_cart');
		
			// Catch WooCommerce cart contents change event
			if ($track_add_to_cart)
			{	add_filter
				(	'woocommerce_cart_contents_changed',
					function($cart) use(&$update_cart)
					{	$update_cart = "(self.personyze=self.personyze||[]).push(['Products Removed from cart']);\n";
						foreach ($cart as $product)
						{	$update_cart .= "(self.personyze=self.personyze||[]).push(['Product Added to cart', {$product['product_id']}, 'quantity', {$product['quantity']}]);\n";
						}
						return $cart;
					},
					INF
				);
			}
			
			add_action
			(	'wp_print_scripts',
				function() use(&$update_cart)
				{	$account_id = (int)get_option('personyze_account_id');
					$tracking_domains = (string)get_option('personyze_tracking_domains');
					$track_purchase = (bool)get_option('personyze_track_purchase');

					if ($account_id>0 and strlen($tracking_domains)>0 and !is_admin())
					{	$tracking_domains_json = json_encode($tracking_domains);
						$page_id = (int)get_queried_object_id();
						$type = get_post_type();
						$what = $type=='product' ? 'Product' : 'Article';
						$nonce_json = json_encode(wp_create_nonce('personyze-nonce'));

						// Is WooCommerce "Thank You" page?
						if ($track_purchase and function_exists('is_wc_endpoint_url') and is_wc_endpoint_url('order-received'))
						{	$order_id = get_query_var('order-received');
							if (get_post_type($order_id) == 'shop_order')
							{	if (function_exists('wc_get_order'))
								{	$order = wc_get_order($order_id);
									foreach ($order->get_items() as $item)
									{	$product = $item->get_data();
										$update_cart .= "(self.personyze=self.personyze||[]).push(['Product Purchased', {$product['product_id']}, 'quantity', {$product['quantity']}]);\n";
									}
								}
							}
						}
		
						echo "<script>
_S_T_NONCE = $nonce_json;
(self.personyze=self.personyze||[]).push(['$what Viewed', $page_id]);
$update_cart
window._S_T ||
(function(d){
	var s = d.createElement('script'),
		u = s.onload===undefined && s.onreadystatechange===undefined,
		i = 0,
		f = function() {window._S_T ? (_S_T.async=true) && _S_T.setup($account_id, $tracking_domains_json) : i++<120 && setTimeout(f, 600)},
		h = d.getElementsByTagName('head');
	s.async = true;
	s.src = '\/\/counter.personyze.com\/stat-track-lib.js';
	s.onload = s.onreadystatechange = f;
	(h && h[0] || d.documentElement).appendChild(s);
	if (u) f();
})(document);
</script>";
					}
				},
				-INF
			);
		},
		-INF
	);

	/*	3. AJAX endpoint for adding products to cart from Personyze widgets on site.
	 */
	$add_to_cart = function()
	{	check_ajax_referer('personyze-nonce', '_ajax_nonce');
		$products = json_decode(wp_unslash($_POST['products'] ?? ''), true);
		if (!is_array($products))
		{	wp_send_json_error(new WP_Error(1, 'Invalid argument'), 500);
		}
		else if (!function_exists('WC'))
		{	wp_send_json_error(new WP_Error(1, 'WooCommerce not installed or not activated'), 500);
		}
		else
		{	$added = [];
			foreach ($products as $product)
			{	if (is_array($product))
				{	$internal_id = intval($product['internal_id'] ?? 0);
					$quantity = max(1, intval($product['quantity'] ?? 0));
					if ($internal_id > 0)
					{	$result = WC()->cart->add_to_cart($internal_id, $quantity);
						if ($result)
						{	$added[] = ['internal_id' => $internal_id, 'quantity' => $quantity];
						}
					}
				}
			}
			wp_send_json(['added' => $added]);
		}
	};
	add_action('wp_ajax_personyze_add_to_cart', $add_to_cart);
	add_action('wp_ajax_nopriv_personyze_add_to_cart', $add_to_cart);

	/*	4. Show notice in the admin page, if not all the parameters are configured.
	 */
	add_action
	(	'init',
		function()
		{	if (!isset($_POST['submit']) and $_GET['page']!='personyze-config')
			{	$account_id = (int)get_option('personyze_account_id');
				$tracking_domains = (string)get_option('personyze_tracking_domains');
				if (!($account_id>0 and strlen($tracking_domains)>0))
				{	add_action
					(	'admin_notices',
						function()
						{	echo "<div class='updated fade'><p><b>".__('Personyze Tracker is almost ready.')."</b> ".sprintf(__('Please, <a href="%1$s">complete the setup</a>.'), "options-general.php?page=personyze-config")."</p></div>";
						}
					);
				}
			}
		},
		-INF
	);

	/*	5. Add "Personyze Configuration" menu item to the "Settings" menu.
	 */
	add_action
	(	'admin_menu',
		function()
		{	add_submenu_page
			(	'options-general.php',
				__('Personyze Configuration'),
				__('Personyze Configuration'),
				'manage_options',
				'personyze-config',
				// Show the tracker config page
				function()
				{	$account_id = (int)get_option('personyze_account_id');
					$tracking_domains = (string)get_option('personyze_tracking_domains');
					?>
					
					<div class="wrap">
						<h2><?php _e('Personyze Configuration')?></h2>
						
						<div class="narrow">
							<?php if (!($account_id>0 and strlen($tracking_domains)>0)):?>
								<p>Please, sign up to <a href="https://www.personyze.com/" target="_blank">Personyze</a>, if you didn't yet (you can choose a free package). You don't need to embed Personyze tracking code manually.</p>

								<p>Then go to <a href="https://personyze.com/panel#cat=Account%20settings%2FMain%20settings%2FIntegrations" target="_blank">Integrations page</a>, and enter the following in Wordpress configuration widget:</p>

								<ol>
									<li>The URL of this site: <b><?php echo $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/'?></b></li>
									<li>Name of wordpress user, that has "manage" permission.</li>
									<li>While you're logged in with that user, go to <a href="https://<?php echo $_SERVER['HTTP_HOST']?>/wp-admin/profile.php#application-passwords-section">Profile</a>, find "Application Passwords" section, and create a password, if you didn't do this before. Copy/paste this password to Personyze.</li>
									<li>Finally click "Save", and "Test"</li>
									<li>If the "Test" was successful, the Personyze tracking code will be injected to this site, and you'll be able to use Wordpress Shortcodes in Personyze actions.</li>
								</ol>
							<?php else:?>
								<p>Your account on Personyze is set up. If you experience a problem, please go to <a href="https://personyze.com/panel#cat=Account%20settings%2FMain%20settings%2FIntegrations" target="_blank">Integrations page</a> on Personyze, and click "Test" in Wordpress configuration widget. If no error is reported, try using the integration again. If it still doesn't work, please feel free to <a href="mailto:support@personyze.com">let the Personyze support know</a></p>
							<?php endif?>

							<a id="personyze-figure-integration-widget" href="javascript:">Figure</a>
							<div>
								<img src="<?php echo plugins_url('assets/integration-widget.png', __FILE__)?>">
							</div>
							<style>
								#personyze-figure-integration-widget + div
								{	position: absolute;
									opacity: 0;
									transition: opacity ease 0.4s;
								}
								#personyze-figure-integration-widget:hover + div,
								#personyze-figure-integration-widget:focus + div
								{	opacity: 1;
								}
							</style>
						</div>
					</div>

					<?php
				}
			);
		},
		-INF
	);
}
