<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Builds an importable n8n workflow JSON from plugin settings.
 */
class Ciwa_Workflow_Exporter {

	private $settings;
	private $nodes = array();
	private $connections = array();
	private $site_url;
	private $ck;
	private $cs;

	public function __construct($settings = null) {
		$this->settings = $settings ? $settings : Ciwa_Settings::get();
		$this->site_url = untrailingslashit(home_url());
		$this->ck       = (string) $this->settings['wc_ck'];
		$this->cs       = (string) $this->settings['wc_cs'];
	}

	public function export() {
		$this->nodes       = array();
		$this->connections = array();

		$this->add_sticky(
			'راهنما',
			"## CIWA Automation\n\nورکفلو توسط افزونه CIWA Automation ساخته شده.\n\n1. این فایل را در n8n ایمپورت و **Active** کن\n2. در CIWA آدرس واقعی را فعال کن تا وبهوک‌های ووکامرس ساخته شوند\n\nState با `_ciwa_journey_token` لغو می‌شود.",
			-900,
			-100,
			520,
			280
		);

		$paths = Ciwa_Settings::webhook_paths();

		// Ping webhook for connection test
		$ping = $this->add_webhook('WH Ping', $paths['ping'], -280, -700);
		$pong = $this->add_code('Pong', "return [{ json: { ok: true, service: 'ciwa', at: new Date().toISOString(), echo: \$input.first().json } }];", 40, -700);
		$this->link($ping, $pong);


		// Customer
		if (!empty($this->settings['stages']['register']['enabled'])) {
			$this->build_register_branch($paths['customer']);
		}

		// Order
		$this->build_order_branch($paths['order']);

		// Cart
		if (!empty($this->settings['stages']['cart']['enabled'])) {
			$this->build_cart_branch($paths['cart']);
		}

		return array(
			'name'        => 'CIWA Automation Journey',
			'nodes'       => $this->nodes,
			'pinData'     => (object) array(),
			'connections' => $this->connections,
			'active'      => false,
			'settings'    => array(
				'executionOrder'        => 'v1',
				'timezone'              => 'Asia/Tehran',
				'saveManualExecutions'  => true,
				'saveExecutionProgress' => true,
			),
			'meta'        => array(
				'templateCredsSetupCompleted' => false,
				'generatedBy'                 => 'ciwa-automation',
				'generatedAt'                 => gmdate('c'),
				'site'                        => $this->site_url,
			),
			'tags'        => array(),
		);
	}

	public function export_json() {
		return wp_json_encode($this->export(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
	}

	/* -------------------- builders -------------------- */

	private function build_register_branch($path) {
		$wh   = $this->add_webhook('WH Customer Created', $path, -280, -500);
		$boot = $this->add_code('Boot Customer', $this->js_boot_customer(), 40, -500);
		$if   = $this->add_if('Has Phone? Register', '={{ $json.skip }}', false, 280, -500);
		$save = $this->add_code('Save State Registered', $this->js_save_customer_state('registered'), 520, -500);
		$skip = $this->add_noop('Skip No Phone Register', 520, -360);

		$this->link($wh, $boot);
		$this->link($boot, $if);
		$this->link($if, $save, 0);
		$this->link($if, $skip, 1);

		$y = -620;
		foreach ($this->enabled_steps('register') as $step) {
			$wait = $this->add_wait('Wait ' . $step['id'], (int) $step['delay_amount'], $step['delay_unit'], 760, $y);
			$gate = $this->add_code(
				'Gate ' . $step['id'],
				$this->js_gate_nurture($step['id'], $step['pattern_code']),
				1000,
				$y
			);
			$this->add_send_chain($gate, $step['id'], $y);
			$this->link($save, $wait);
			$this->link($wait, $gate);
			$y += 200;
		}
	}

	private function build_order_branch($path) {
		$wh   = $this->add_webhook('WH Order Updated', $path, -280, 200);
		$boot = $this->add_code('Boot Order', $this->js_boot_order(), 40, 200);
		$save = $this->add_code('Save State Order', $this->js_save_order_state(), 280, 200);
		$dup  = $this->add_if('New State?', '={{ $json.abort_route }}', false, 480, 200);
		$skip = $this->add_noop('Skip Duplicate Webhook', 700, 360);
		$sw   = $this->add_switch_order(700, 200);

		$this->link($wh, $boot);
		$this->link($boot, $save);
		$this->link($save, $dup);
		$this->link($dup, $sw, 0);
		$this->link($dup, $skip, 1);

		// pending output 0
		$y = 40;
		if (!empty($this->settings['stages']['pending']['enabled'])) {
			foreach ($this->enabled_steps('pending') as $step) {
				$wait = $this->add_wait('Wait ' . $step['id'], (int) $step['delay_amount'], $step['delay_unit'], 960, $y);
				$gate = $this->add_code(
					'Gate ' . $step['id'],
					$this->js_gate_pending($step['pattern_code']),
					1200,
					$y
				);
				$this->add_send_chain($gate, $step['id'], $y);
				$this->link($sw, $wait, 0);
				$this->link($wait, $gate);
				$y += 140;
			}
		} else {
			$this->link($sw, $this->add_noop('Pending Disabled', 960, 40), 0);
		}

		// failed output 1
		$y = 420;
		$last_sms = null;
		if (!empty($this->settings['stages']['failed']['enabled'])) {
			$steps = $this->enabled_steps('failed');
			foreach ($steps as $i => $step) {
				$wait = $this->add_wait('Wait ' . $step['id'], (int) $step['delay_amount'], $step['delay_unit'], 960, $y);
				$gate = $this->add_code(
					'Gate ' . $step['id'],
					$this->js_gate_failed($step['pattern_code']),
					1200,
					$y
				);
				$sms_name = $this->add_send_chain($gate, $step['id'], $y);
				$this->link($sw, $wait, 1);
				$this->link($wait, $gate);
				if ($i === count($steps) - 1) {
					$exit = $this->add_code('Exit After Failed Last', $this->js_exit_failed(), 1960, $y);
					$this->link($sms_name, $exit);
				}
				$y += 140;
			}
		} else {
			$this->link($sw, $this->add_noop('Failed Disabled', 960, 420), 1);
		}

		// purchased output 2
		if (!empty($this->settings['stages']['purchase']['enabled'])) {
			$steps = $this->enabled_steps('purchase');
			foreach ($steps as $step) {
				if ((int) $step['delay_amount'] === 0) {
					$prep = $this->add_code(
						'Prep ' . $step['id'],
						$this->js_prep_immediate($step['pattern_code']),
						960,
						820
					);
					$this->add_send_chain($prep, $step['id'], 820);
					$this->link($sw, $prep, 2);
				} else {
					$wait = $this->add_wait('Wait ' . $step['id'], (int) $step['delay_amount'], $step['delay_unit'], 960, 980);
					$gate = $this->add_code(
						'Gate ' . $step['id'],
						$this->js_gate_satisfaction($step['pattern_code']),
						1200,
						980
					);
					$this->add_send_chain($gate, $step['id'], 980);
					$this->link($sw, $wait, 2);
					$this->link($wait, $gate);
				}
			}
		} else {
			$this->link($sw, $this->add_noop('Purchase Disabled', 960, 820), 2);
		}

		$this->link($sw, $this->add_noop('Ignore Other Status', 960, 1120), 3);
	}

	private function build_cart_branch($path) {
		$wh   = $this->add_webhook('WH Cart Abandoned', $path, -280, 1300);
		$boot = $this->add_code('Boot Cart', $this->js_boot_cart(), 40, 1300);
		$if   = $this->add_if('Has Phone? Cart', '={{ $json.skip }}', false, 280, 1300);
		$save = $this->add_code('Save State Cart', $this->js_save_customer_state('cart_abandoned'), 520, 1300);
		$skip = $this->add_noop('Skip No Phone Cart', 520, 1440);

		$this->link($wh, $boot);
		$this->link($boot, $if);
		$this->link($if, $save, 0);
		$this->link($if, $skip, 1);

		$y = 1180;
		foreach ($this->enabled_steps('cart') as $step) {
			$wait = $this->add_wait('Wait ' . $step['id'], (int) $step['delay_amount'], $step['delay_unit'], 760, $y);
			$gate = $this->add_code(
				'Gate ' . $step['id'],
				$this->js_gate_cart($step['pattern_code']),
				1000,
				$y
			);
			$this->add_send_chain($gate, $step['id'], $y);
			$this->link($save, $wait);
			$this->link($wait, $gate);
			$y += 140;
		}
	}

	/**
	 * @return string name of SMS node (true branch)
	 */
	private function add_send_chain($gate_name, $id, $y) {
		$send_if = $this->add_if('Send? ' . $id, '={{ $json.send }}', true, 1480, $y);
		$sms     = $this->add_http_sms('SMS ' . $id, 1720, $y);
		$skip    = $this->add_noop('Skip ' . $id, 1720, $y + 90);
		$this->link($gate_name, $send_if);
		$this->link($send_if, $sms, 0);
		$this->link($send_if, $skip, 1);
		return $sms;
	}

	private function enabled_steps($stage_key) {
		$stage = isset($this->settings['stages'][$stage_key]) ? $this->settings['stages'][$stage_key] : array();
		$steps = isset($stage['steps']) ? $stage['steps'] : array();
		$out   = array();
		foreach ($steps as $step) {
			if (!empty($step['enabled'])) {
				$out[] = $step;
			}
		}
		return $out;
	}

	/* -------------------- node helpers -------------------- */

	private function uid() {
		if (function_exists('wp_generate_uuid4')) {
			return wp_generate_uuid4();
		}
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand(0, 0xffff),
			mt_rand(0, 0xffff),
			mt_rand(0, 0xffff),
			mt_rand(0, 0x0fff) | 0x4000,
			mt_rand(0, 0x3fff) | 0x8000,
			mt_rand(0, 0xffff),
			mt_rand(0, 0xffff),
			mt_rand(0, 0xffff)
		);
	}

	private function add_node($name, $type, $type_version, $params, $x, $y, $extra = array()) {
		$node = array_merge(
			array(
				'parameters'  => $params,
				'type'        => $type,
				'typeVersion' => $type_version,
				'position'    => array($x, $y),
				'id'          => $this->uid(),
				'name'        => $name,
			),
			$extra
		);
		$this->nodes[] = $node;
		return $name;
	}

	private function link($src, $dst, $out = 0) {
		if (!isset($this->connections[$src])) {
			$this->connections[$src] = array('main' => array());
		}
		while (count($this->connections[$src]['main']) <= $out) {
			$this->connections[$src]['main'][] = array();
		}
		$this->connections[$src]['main'][$out][] = array(
			'node'  => $dst,
			'type'  => 'main',
			'index' => 0,
		);
	}

	private function add_sticky($name, $content, $x, $y, $w, $h) {
		return $this->add_node($name, 'n8n-nodes-base.stickyNote', 1, array(
			'content' => $content,
			'height'  => $h,
			'width'   => $w,
			'color'   => 5,
		), $x, $y);
	}

	private function add_webhook($name, $path, $x, $y) {
		return $this->add_node($name, 'n8n-nodes-base.webhook', 2, array(
			'httpMethod'   => 'POST',
			'path'         => $path,
			'responseMode' => 'onReceived',
			'options'      => (object) array(),
		), $x, $y, array('webhookId' => $path));
	}

	private function add_code($name, $js, $x, $y) {
		return $this->add_node($name, 'n8n-nodes-base.code', 2, array('jsCode' => $js), $x, $y);
	}

	private function add_wait($name, $amount, $unit, $x, $y) {
		$unit = in_array($unit, array('seconds', 'minutes', 'hours', 'days'), true) ? $unit : 'hours';
		return $this->add_node($name, 'n8n-nodes-base.wait', 1.1, array(
			'resume' => 'timeInterval',
			'amount' => max(0, (int) $amount),
			'unit'   => $unit,
		), $x, $y, array('webhookId' => $this->uid()));
	}

	private function add_noop($name, $x, $y) {
		return $this->add_node($name, 'n8n-nodes-base.noOp', 1, array(), $x, $y);
	}

	private function add_if($name, $left, $expect_true, $x, $y) {
		$op = $expect_true
			? array('type' => 'boolean', 'operation' => 'true', 'singleValue' => true)
			: array('type' => 'boolean', 'operation' => 'false', 'singleValue' => true);
		return $this->add_node($name, 'n8n-nodes-base.if', 2.2, array(
			'conditions' => array(
				'combinator' => 'and',
				'conditions' => array(
					array(
						'id'         => $this->uid(),
						'leftValue'  => $left,
						'rightValue' => $expect_true,
						'operator'   => $op,
					),
				),
			),
			'options'    => (object) array(),
		), $x, $y);
	}

	private function add_switch_order($x, $y) {
		$make = function ($value, $key) {
			return array(
				'conditions'   => array(
					'options'    => array('caseSensitive' => true, 'leftValue' => '', 'typeValidation' => 'strict'),
					'conditions' => array(
						array(
							'leftValue'  => '={{ $json.state }}',
							'rightValue' => $value,
							'operator'   => array('type' => 'string', 'operation' => 'equals'),
							'id'         => $this->uid(),
						),
					),
					'combinator' => 'and',
				),
				'renameOutput' => true,
				'outputKey'    => $key,
			);
		};
		return $this->add_node('Route Order State', 'n8n-nodes-base.switch', 3.2, array(
			'rules'   => array(
				'values' => array(
					$make('checkout_pending', 'pending'),
					$make('payment_failed', 'failed'),
					$make('purchased', 'purchased'),
				),
			),
			'options' => array('fallbackOutput' => 'extra'),
		), $x, $y);
	}

	private function add_http_sms($name, $x, $y) {
		$cfg = Ciwa_Sms::n8n_http_config();
		return $this->add_node($name, 'n8n-nodes-base.httpRequest', 4.2, array(
			'method'           => 'POST',
			'url'              => $cfg['url'],
			'sendHeaders'      => true,
			'headerParameters' => array(
				'parameters' => $cfg['headers'],
			),
			'sendBody'         => true,
			'specifyBody'      => 'json',
			'jsonBody'         => $cfg['jsonBody'],
			'options'          => array(
				'timeout'  => 30000,
				'response' => array('response' => array('neverError' => true)),
			),
		), $x, $y);
	}

	/* -------------------- JS templates -------------------- */

	private function js_cfg() {
		$cfg = array(
			'site_url'        => $this->site_url,
			'wc_ck'           => $this->ck,
			'wc_cs'           => $this->cs,
			'sms_provider'    => Ciwa_Sms::current(),
			'sms_api_key'     => Ciwa_Sms::api_key(),
			'mediana_api_key' => Ciwa_Sms::api_key(), // legacy field name in older nodes
			'from_number'     => (string) $this->settings['from_number'],
		);
		return 'const cfg = ' . wp_json_encode($cfg, JSON_UNESCAPED_UNICODE) . ";\n";
	}

	private function js_phone_helpers() {
		return <<<'JS'
function normalizePhone(raw) {
  if (!raw) return '';
  let p = String(raw).trim().replace(/[\s-]/g, '');
  if (p.includes('@')) {
    const local = p.split('@')[0];
    if (/^09\d{9}$/.test(local)) p = local;
  }
  if (p.startsWith('+98')) p = '0' + p.slice(3);
  if (p.startsWith('98') && p.length === 12) p = '0' + p.slice(2);
  if (p.startsWith('9') && p.length === 10) p = '0' + p;
  if (!/^09\d{9}$/.test(p)) return '';
  return '+98' + p.slice(1);
}
function newToken() {
  return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
}

JS;
	}

	private function js_boot_customer() {
		return $this->js_cfg() . $this->js_phone_helpers() . <<<'JS'
const body = $input.first().json;
const cust = body.id ? body : (body.body || body);
const phone = normalizePhone(cust?.billing?.phone || cust?.shipping?.phone || cust?.email || '');
const customerId = cust?.id || cust?.customer_id || 0;
const firstName = cust?.first_name || cust?.billing?.first_name || 'دوست';
const token = newToken();
if (!phone) return [{ json: { ...cfg, skip: true, reason: 'no_phone', customerId, token, state: 'registered' } }];
return [{ json: { ...cfg, skip: false, customerId, phone, firstName, token, state: 'registered', eventAt: new Date().toISOString(), source: 'customer.created' } }];
JS;
	}

	private function js_boot_order() {
		return $this->js_cfg() . $this->js_phone_helpers() . <<<'JS'
const body = $input.first().json;
const order = body.id && body.status ? body : (body.body || body);
const status = order.status;
const phone = normalizePhone(order?.billing?.phone || order?.shipping?.phone || order?.billing?.email || '');
const customerId = order.customer_id || 0;
const firstName = order?.billing?.first_name || 'دوست';
const orderId = order.id;
const orderKey = order.order_key;
const token = newToken();
let state = 'order_' + status;
if (['processing', 'completed'].includes(status)) state = 'purchased';
if (status === 'pending' || status === 'on-hold') state = 'checkout_pending';
if (status === 'failed') state = 'payment_failed';
return [{ json: {
  ...cfg, skip: !phone, reason: phone ? '' : 'no_phone', customerId, phone, firstName, token, state, status,
  orderId, orderKey, datePaid: order.date_paid, eventAt: new Date().toISOString(), source: 'order.updated',
  payUrl: `${cfg.site_url}/checkout/order-pay/${orderId}/?pay_for_order=true&key=${orderKey}`,
}}];
JS;
	}

	private function js_boot_cart() {
		return $this->js_cfg() . $this->js_phone_helpers() . <<<'JS'
const body = $input.first().json;
const payload = (body.phone || body.billing || body.customer_id) ? body : (body.body || body);
const phone = normalizePhone(payload.phone || payload.billing?.phone || payload.email || '');
const customerId = payload.customer_id || payload.customerId || 0;
const firstName = payload.first_name || payload.firstName || payload.billing?.first_name || 'دوست';
const token = payload.token || newToken();
const cartUrl = payload.cart_url || payload.checkout_url || `${cfg.site_url}/cart/`;
if (!phone) return [{ json: { ...cfg, skip: true, reason: 'no_phone', token, state: 'cart_abandoned' } }];
return [{ json: { ...cfg, skip: false, customerId, phone, firstName, token, state: 'cart_abandoned', cartUrl, eventAt: payload.event_at || new Date().toISOString(), source: 'cart.abandoned' } }];
JS;
	}

	private function js_save_customer_state($state) {
		$state_js = wp_json_encode($state);
		return <<<JS
const ctx = \$input.first().json;
if (ctx.skip || !ctx.customerId) return [{ json: ctx }];
const auth = \`consumer_key=\${ctx.wc_ck}&consumer_secret=\${ctx.wc_cs}\`;
await this.helpers.httpRequest({
  method: 'PUT',
  url: \`\${ctx.site_url}/wp-json/wc/v3/customers/\${ctx.customerId}?\${auth}\`,
  body: { meta_data: [
    { key: '_ciwa_journey_state', value: {$state_js} },
    { key: '_ciwa_journey_token', value: ctx.token },
    { key: '_ciwa_journey_event_at', value: ctx.eventAt },
  ]},
  json: true,
});
return [{ json: ctx }];
JS;
	}

	private function js_save_order_state() {
		return <<<'JS'
const ctx = $input.first().json;
const auth = `consumer_key=${ctx.wc_ck}&consumer_secret=${ctx.wc_cs}`;
let prevState = '', prevOrderId = '', prevToken = '';
try {
  if (ctx.customerId) {
    const cust = await this.helpers.httpRequest({ method: 'GET', url: `${ctx.site_url}/wp-json/wc/v3/customers/${ctx.customerId}?${auth}`, json: true });
    const meta = Object.fromEntries((cust.meta_data || []).map(m => [m.key, m.value]));
    prevState = meta._ciwa_journey_state || '';
    prevOrderId = meta._ciwa_journey_order_id || '';
    prevToken = meta._ciwa_journey_token || '';
  }
} catch (e) {}
const sameJourney = prevState === ctx.state && String(prevOrderId) === String(ctx.orderId) && ['checkout_pending','payment_failed','purchased'].includes(ctx.state);
if (sameJourney) return [{ json: { ...ctx, abort_route: true, reason: 'duplicate_order_webhook', token: prevToken } }];
const meta = [
  { key: '_ciwa_journey_state', value: ctx.state },
  { key: '_ciwa_journey_token', value: ctx.token },
  { key: '_ciwa_journey_event_at', value: ctx.eventAt },
  { key: '_ciwa_journey_order_id', value: String(ctx.orderId) },
];
if (ctx.customerId) {
  await this.helpers.httpRequest({ method: 'PUT', url: `${ctx.site_url}/wp-json/wc/v3/customers/${ctx.customerId}?${auth}`, body: { meta_data: meta }, json: true });
}
await this.helpers.httpRequest({ method: 'PUT', url: `${ctx.site_url}/wp-json/wc/v3/orders/${ctx.orderId}?${auth}`, body: { meta_data: meta }, json: true });
return [{ json: { ...ctx, abort_route: false } }];
JS;
	}

	private function js_gate_nurture($id, $pattern) {
		$pattern = wp_json_encode((string) $pattern);
		return <<<JS
const ctx = \$input.first().json;
const auth = \`consumer_key=\${ctx.wc_ck}&consumer_secret=\${ctx.wc_cs}\`;
const custRes = await this.helpers.httpRequest({ method: 'GET', url: \`\${ctx.site_url}/wp-json/wc/v3/customers/\${ctx.customerId}?\${auth}\`, json: true });
const meta = Object.fromEntries((custRes.meta_data || []).map(m => [m.key, m.value]));
if (meta._ciwa_journey_token !== ctx.token) return [{ json: { ...ctx, send: false, reason: 'token_mismatch' } }];
const orders = await this.helpers.httpRequest({ method: 'GET', url: \`\${ctx.site_url}/wp-json/wc/v3/orders?customer=\${ctx.customerId}&status=processing,completed&per_page=1&\${auth}\`, json: true });
if (Array.isArray(orders) && orders.length > 0) return [{ json: { ...ctx, send: false, reason: 'already_purchased' } }];
return [{ json: { ...ctx, send: true, patternCode: {$pattern}, patternParams: { name: ctx.firstName } } }];
JS;
	}

	private function js_gate_pending($pattern) {
		$pattern = wp_json_encode((string) $pattern);
		return <<<JS
const ctx = \$input.first().json;
const auth = \`consumer_key=\${ctx.wc_ck}&consumer_secret=\${ctx.wc_cs}\`;
const order = await this.helpers.httpRequest({ method: 'GET', url: \`\${ctx.site_url}/wp-json/wc/v3/orders/\${ctx.orderId}?\${auth}\`, json: true });
const ometa = Object.fromEntries((order.meta_data || []).map(m => [m.key, m.value]));
if (ometa._ciwa_journey_token !== ctx.token) return [{ json: { ...ctx, send: false, reason: 'token_mismatch' } }];
if (['processing','completed'].includes(order.status) || order.date_paid) return [{ json: { ...ctx, send: false, reason: 'paid' } }];
if (!['pending','on-hold'].includes(order.status)) return [{ json: { ...ctx, send: false, reason: 'status_' + order.status } }];
return [{ json: { ...ctx, send: true, patternCode: {$pattern}, patternParams: { name: ctx.firstName, order_id: String(ctx.orderId), pay_url: ctx.payUrl } } }];
JS;
	}

	private function js_gate_failed($pattern) {
		$pattern = wp_json_encode((string) $pattern);
		return <<<JS
const ctx = \$input.first().json;
const auth = \`consumer_key=\${ctx.wc_ck}&consumer_secret=\${ctx.wc_cs}\`;
const order = await this.helpers.httpRequest({ method: 'GET', url: \`\${ctx.site_url}/wp-json/wc/v3/orders/\${ctx.orderId}?\${auth}\`, json: true });
const ometa = Object.fromEntries((order.meta_data || []).map(m => [m.key, m.value]));
if (ometa._ciwa_journey_token !== ctx.token) return [{ json: { ...ctx, send: false, reason: 'token_mismatch' } }];
if (['processing','completed'].includes(order.status) || order.date_paid) return [{ json: { ...ctx, send: false, reason: 'paid_exit' } }];
if (order.status !== 'failed') return [{ json: { ...ctx, send: false, reason: 'status_' + order.status } }];
return [{ json: { ...ctx, send: true, patternCode: {$pattern}, patternParams: { name: ctx.firstName, order_id: String(ctx.orderId), pay_url: ctx.payUrl } } }];
JS;
	}

	private function js_gate_cart($pattern) {
		$pattern = wp_json_encode((string) $pattern);
		return <<<JS
const ctx = \$input.first().json;
const auth = \`consumer_key=\${ctx.wc_ck}&consumer_secret=\${ctx.wc_cs}\`;
if (ctx.customerId) {
  const cust = await this.helpers.httpRequest({ method: 'GET', url: \`\${ctx.site_url}/wp-json/wc/v3/customers/\${ctx.customerId}?\${auth}\`, json: true });
  const meta = Object.fromEntries((cust.meta_data || []).map(m => [m.key, m.value]));
  if (meta._ciwa_journey_token !== ctx.token) return [{ json: { ...ctx, send: false, reason: 'token_mismatch' } }];
  const orders = await this.helpers.httpRequest({ method: 'GET', url: \`\${ctx.site_url}/wp-json/wc/v3/orders?customer=\${ctx.customerId}&status=processing,completed,pending,on-hold,failed&per_page=5&\${auth}\`, json: true });
  const after = new Date(ctx.eventAt).getTime();
  const recent = (orders || []).filter(o => new Date(o.date_created).getTime() >= after - 60000);
  if (recent.some(o => ['processing','completed'].includes(o.status) || o.date_paid)) return [{ json: { ...ctx, send: false, reason: 'purchased' } }];
  if (recent.some(o => ['pending','on-hold','failed'].includes(o.status))) return [{ json: { ...ctx, send: false, reason: 'moved_to_checkout' } }];
}
return [{ json: { ...ctx, send: true, patternCode: {$pattern}, patternParams: { name: ctx.firstName, cart_url: ctx.cartUrl } } }];
JS;
	}

	private function js_gate_satisfaction($pattern) {
		$pattern = wp_json_encode((string) $pattern);
		return <<<JS
const ctx = \$input.first().json;
const auth = \`consumer_key=\${ctx.wc_ck}&consumer_secret=\${ctx.wc_cs}\`;
const order = await this.helpers.httpRequest({ method: 'GET', url: \`\${ctx.site_url}/wp-json/wc/v3/orders/\${ctx.orderId}?\${auth}\`, json: true });
if (ctx.customerId) {
  const cust = await this.helpers.httpRequest({ method: 'GET', url: \`\${ctx.site_url}/wp-json/wc/v3/customers/\${ctx.customerId}?\${auth}\`, json: true });
  const cmeta = Object.fromEntries((cust.meta_data || []).map(m => [m.key, m.value]));
  if (cmeta._ciwa_journey_token !== ctx.token) return [{ json: { ...ctx, send: false, reason: 'token_mismatch' } }];
}
if (!['completed','processing'].includes(order.status)) return [{ json: { ...ctx, send: false, reason: 'not_done' } }];
return [{ json: { ...ctx, send: true, patternCode: {$pattern}, patternParams: { name: ctx.firstName, order_id: String(ctx.orderId) } } }];
JS;
	}

	private function js_prep_immediate($pattern) {
		$pattern = wp_json_encode((string) $pattern);
		return <<<JS
const ctx = \$input.first().json;
return [{ json: { ...ctx, send: !ctx.skip, patternCode: {$pattern}, patternParams: { name: ctx.firstName, order_id: String(ctx.orderId) } } }];
JS;
	}

	private function js_exit_failed() {
		return <<<'JS'
const ctx = $input.first().json;
if (!ctx.send) return [{ json: ctx }];
const auth = `consumer_key=${ctx.wc_ck}&consumer_secret=${ctx.wc_cs}`;
const token = Date.now().toString(36) + '-exit';
const meta = [ { key: '_ciwa_journey_state', value: 'exited_payment_failed' }, { key: '_ciwa_journey_token', value: token } ];
if (ctx.customerId) {
  await this.helpers.httpRequest({ method: 'PUT', url: `${ctx.site_url}/wp-json/wc/v3/customers/${ctx.customerId}?${auth}`, body: { meta_data: meta }, json: true });
}
return [{ json: { ...ctx, state: 'exited_payment_failed' } }];
JS;
	}
}
