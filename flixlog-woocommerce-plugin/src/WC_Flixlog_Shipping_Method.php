<?php

defined('ABSPATH') || exit;

class WC_Flixlog_Shipping_Method extends WC_Shipping_Method
{

    /**
     * Cost passed to [fee] shortcode.
     *
     * @var string Cost.
     */
    protected $fee_cost = '';

    public function __construct($instance_id = 0)
    {
        $this->id = 'flixlog_shipping_method';
        $this->instance_id = absint($instance_id);
        $this->supports = [
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal',
        ];
        $this->method_title = __('Flixlog', 'flixlog-woocommerce-plugin');
        $this->method_description = __('The most efficient way to send your packages', 'flixlog-woocommerce-plugin'); //
        $this->init();

        add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
    }

    /**
     * Init user set variables.
     */
    public function init()
    {
        $this->instance_form_fields = $this->retrieve_form_fields();
        $this->title = $this->get_option('title');
        $this->tax_status = $this->get_option('tax_status');
        $this->type = $this->get_option('type', 'class');
    }

    private function retrieve_form_fields()
    {
        return [
            'title' => [
                'title' => __('Method title', 'woocommerce'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                'default' => __('Flixlog', 'flixlog-woocommerce-plugin'),
                'desc_tip' => true,
            ],
            'origin_postcode' => [
                'title' => __('Origin Postcode', 'flixlog-woocommerce-plugin'),
                'type' => 'text',
                'description' => __('Postcode of the warehouse attending this zone', 'flixlog-woocommerce-plugin'),
                'desc_tip' => true,
            ],
            'access_token' => [
                'title' => __('Access Token', 'flixlog-woocommerce-plugin'),
                'type' => 'text',
                'description' => __('Access token provided by Flixlog', 'flixlog-woocommerce-plugin'),
                'desc_tip' => true,
            ],
        ];
    }

    /**
     * Work out fee (shortcode).
     *
     * @param array $atts Attributes.
     *
     * @return string
     */
    public function fee($atts)
    {
        $atts = shortcode_atts(
            [
                'percent' => '',
                'min_fee' => '',
                'max_fee' => '',
            ],
            $atts,
            'fee'
        );

        $calculated_fee = 0;

        if ($atts['percent']) {
            $calculated_fee = $this->fee_cost * (floatval($atts['percent']) / 100);
        }

        if ($atts['min_fee'] && $calculated_fee < $atts['min_fee']) {
            $calculated_fee = $atts['min_fee'];
        }

        if ($atts['max_fee'] && $calculated_fee > $atts['max_fee']) {
            $calculated_fee = $atts['max_fee'];
        }

        return $calculated_fee;
    }

    /**
     * Calculate the shipping costs.
     *
     * @param array $package Package of items from cart.
     */
    public function calculate_shipping($package = [])
    {
        try {
            $this->calculate_cost_from_api($package);
        } catch (\Exception $exception) {
        }
    }

    /**
     * Get items in package.
     *
     * @param array $package Package of items from cart.
     *
     * @return int
     */
    public function get_package_item_qty($package)
    {
        $total_quantity = 0;
        foreach ($package['contents'] as $item_id => $values) {
            if ($values['quantity'] > 0 && $values['data']->needs_shipping()) {
                $total_quantity += $values['quantity'];
            }
        }

        return $total_quantity;
    }

    /**
     * Finds and returns shipping classes and the products with said class.
     *
     * @param mixed $package Package of items from cart.
     *
     * @return array
     */
    public function find_shipping_classes($package)
    {
        $found_shipping_classes = [];

        foreach ($package['contents'] as $item_id => $values) {
            if ($values['data']->needs_shipping()) {
                $found_class = $values['data']->get_shipping_class();

                if (!isset($found_shipping_classes[$found_class])) {
                    $found_shipping_classes[$found_class] = [];
                }

                $found_shipping_classes[$found_class][$item_id] = $values;
            }
        }

        return $found_shipping_classes;
    }

    /**
     * Evaluate a cost from a sum/string.
     *
     * @param string $sum Sum of shipping.
     * @param array $args Args, must contain `cost` and `qty` keys. Having `array()` as default is for back compat reasons.
     *
     * @return string
     */
    protected function evaluate_cost($sum, $args = [])
    {
        // Add warning for subclasses.
        if (!is_array($args) || !array_key_exists('qty', $args) || !array_key_exists('cost', $args)) {
            wc_doing_it_wrong(__FUNCTION__, '$args must contain `cost` and `qty` keys.', '4.0.1');
        }

        include_once WC()->plugin_path() . '/includes/libraries/class-wc-eval-math.php';

        // Allow 3rd parties to process shipping cost arguments.
        $args = apply_filters('woocommerce_evaluate_shipping_cost_args', $args, $sum, $this);
        $locale = localeconv();
        $decimals = [wc_get_price_decimal_separator(), $locale['decimal_point'], $locale['mon_decimal_point'], ','];
        $this->fee_cost = $args['cost'];

        // Expand shortcodes.
        add_shortcode('fee', [$this, 'fee']);

        $sum = do_shortcode(
            str_replace(
                [
                    '[qty]',
                    '[cost]',
                ],
                [
                    $args['qty'],
                    $args['cost'],
                ],
                $sum
            )
        );

        remove_shortcode('fee', [$this, 'fee']);

        // Remove whitespace from string.
        $sum = preg_replace('/\s+/', '', $sum);

        // Remove locale from string.
        $sum = str_replace($decimals, '.', $sum);

        // Trim invalid start/end characters.
        $sum = rtrim(ltrim($sum, "\t\n\r\0\x0B+*/"), "\t\n\r\0\x0B+-*/");

        // Do the math.
        return $sum ? WC_Eval_Math::evaluate($sum) : 0;
    }

    private function calculate_cost_from_api($package)
    {
        $url = 'https://freight.flixlog.com/channel-quote/woocommerce?token=' . $this->get_option('access_token');
        $from = preg_replace('/\D/', '', $this->get_option('origin_postcode'));
        $to = preg_replace('/\D/', '', $package['destination']['postcode']);
        if (empty($from) || empty($to)) {
            return;
        }
        $body = (object)array(
            'from' => $from,
            'to' => $to,
            'parcels' => array(),
        );

        foreach ($package['contents'] as $content) {
            $body->parcels[] = array(
                'reference' => $content['data']->get_sku() ?: '',
                'height' => (float)$content['data']->get_height() / 100,
                'weight' => (float)$content['data']->get_weight(),
                'quantity' => (float)$content['quantity'],
                'width' => (float)$content['data']->get_width() / 100,
                'length' => (float)$content['data']->get_length() / 100,
                'cargo_value' => (float)$content['data']->get_price() * (float)$content['quantity'],
            );
        }

        $httpClient = new WP_Http;
        $response = $httpClient->request(
            $url,
            array(
                'method' => 'POST',
                'headers' => [
                    'Authorization' => 'Token ' . $this->get_option('access_token'),
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode($body)
            )
        )['body'];

        foreach (json_decode($response, true) as $rate) {
            $this->add_rate(
                array(
                    'id' => $this->get_rate_id($rate['carrier_canonical_name'] . ':' . $rate['region']),
                    'label' => sprintf(
                        'Flixlog - %s',
                        strtoupper($rate['carrier_canonical_name'])
                    ),
                    'cost' => $rate['estimated_cost'],
                    'package' => $package,
                    'meta_data' => [
                        'carrier' => strtoupper($rate['carrier_canonical_name']),
                        'delivery_days' => $rate['delivery_days'],
                        'expedition_time' => $rate['expedition_time'],
                        'region' => $rate['region'],
                        'cubed_weight' => $rate['cubed_weight'] . 'kg',
                    ]
                )
            );
        }
    }
}
