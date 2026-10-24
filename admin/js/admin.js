(function ($) {
	'use strict';

	function toast(msg, isError) {
		var $t = $('#ciwa-toast');
		$t.text(msg).prop('hidden', false).toggleClass('is-error', !!isError);
		clearTimeout(toast._t);
		toast._t = setTimeout(function () {
			$t.prop('hidden', true);
		}, 3500);
	}

	function post(action, data) {
		data = data || {};
		data.action = action;
		data.nonce = CiwaAdmin.nonce;
		return $.post(CiwaAdmin.ajax, data);
	}

	function collectFlows() {
		var stages = {};
		$('#ciwa-flows .ciwa-flow').each(function () {
			var key = $(this).data('stage');
			var steps = [];
			$(this).find('.ciwa-step-row').each(function () {
				steps.push({
					enabled: $(this).find('.ciwa-step-enabled').is(':checked') ? 1 : 0,
					delay_amount: parseInt($(this).find('.ciwa-delay').val(), 10) || 0,
					delay_unit: $(this).find('.ciwa-unit').val(),
					pattern_code: $(this).find('.ciwa-pattern').val(),
					channel: $(this).find('.ciwa-channel').val() || 'sms',
					message: $(this).find('.ciwa-message').val() || '',
					message_b: $(this).find('.ciwa-message-b').val() || '',
					ab_enabled: $(this).find('.ciwa-ab-enabled').is(':checked') ? 1 : 0
				});
			});
			stages[key] = {
				enabled: $(this).find('.ciwa-stage-enabled').is(':checked') ? 1 : 0,
				steps: steps
			};
		});
		return stages;
	}

	var unitLabels = { minutes: 'دقیقه', hours: 'ساعت', days: 'روز' };
	var channelLabels = { sms: 'پیامک', email: 'ایمیل', both: 'هر دو' };

	function updateCharCounts() {
		$('.ciwa-step-row').each(function () {
			var text = $(this).find('.ciwa-message').val() || '';
			var len = text.length;
			var unicode = /[^\u0000-\u007F]/.test(text);
			var per = unicode ? 70 : 160;
			var parts = len ? Math.ceil(len / per) : 0;
			$(this).find('.ciwa-char-count').text(len + ' کاراکتر ≈ ' + parts + ' پیامک');
		});
	}

	function updateStepChips($row) {
		var amount = parseInt($row.find('.ciwa-delay').val(), 10) || 0;
		var unit = $row.find('.ciwa-unit').val() || 'minutes';
		var channel = $row.find('.ciwa-channel').val() || 'sms';
		var chips = $row.find('.ciwa-step-sum .ciwa-chip');
		if (chips.eq(0).length) {
			chips.eq(0).text(amount + ' ' + (unitLabels[unit] || unit));
		}
		if (chips.eq(1).length) {
			chips.eq(1).text(channelLabels[channel] || channel);
		}
	}

	$(document).on('input', '.ciwa-message', updateCharCounts);
	$(document).on('change input', '.ciwa-delay, .ciwa-unit, .ciwa-channel', function () {
		updateStepChips($(this).closest('.ciwa-step-row'));
	});
	$(document).on('change', '.ciwa-ab-enabled', function () {
		var on = $(this).is(':checked');
		$(this).closest('.ciwa-ab-box, .ciwa-ab-row').find('.ciwa-message-b').prop('disabled', !on);
	});
	$(updateCharCounts);

	function renderReport(report) {
		var $box = $('#ciwa-test-report').prop('hidden', false).empty();
		var sumClass = report.ok ? 'is-ok' : 'is-fail';
		var html = '<div class="ciwa-report-summary ' + sumClass + '">' + escapeHtml(report.summary) + '</div><ul class="ciwa-report-steps">';
		(report.steps || []).forEach(function (st) {
			html += '<li class="' + (st.ok ? 'ok' : 'fail') + '">' +
				'<span class="mark">' + (st.ok ? '✓' : '✕') + '</span>' +
				'<div><strong>' + escapeHtml(st.label) + '</strong>' +
				(st.detail ? '<small>' + escapeHtml(st.detail) + '</small>' : '') +
				'</div></li>';
		});
		html += '</ul>';
		$box.html(html);
		$('#ciwa-activate-prod').prop('disabled', !report.ok);
	}

	function escapeHtml(s) {
		return String(s || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	/* Engine picker */
	var selectedEngine = CiwaAdmin.engine || '';
	$(document).on('click', '.ciwa-engine-card[data-mode]', function () {
		var mode = $(this).data('mode');
		var $card = $(this);
		post('ciwa_choose_engine', { mode: mode }).done(function (res) {
			if (!res.success) {
				toast((res.data && res.data.message) || CiwaAdmin.i18n.error, true);
				return;
			}
			selectedEngine = mode;
			$('.ciwa-engine-card').removeClass('is-selected');
			$card.addClass('is-selected');
			$('#ciwa-start-setup').prop('disabled', false);
			toast(res.data.message);
			CiwaAdmin.engine = mode;
		});
	});

	/* Welcome */
	$(document).on('click', '#ciwa-start-setup', function () {
		if (!selectedEngine && !CiwaAdmin.engine) {
			toast(CiwaAdmin.i18n.chooseEngine, true);
			return;
		}
		post('ciwa_dismiss_welcome').done(function (res) {
			if (!res.success) {
				toast((res.data && res.data.message) || CiwaAdmin.i18n.chooseEngine, true);
				return;
			}
			window.location = res.data.redirect;
		});
	});

	/* Templates */
	$(document).on('click', '.ciwa-template-card', function () {
		var id = $(this).data('template');
		var $btn = $(this);
		post('ciwa_apply_template', { template: id }).done(function (res) {
			if (!res.success) {
				toast((res.data && res.data.message) || CiwaAdmin.i18n.error, true);
				return;
			}
			toast(res.data.message);
			window.location = res.data.redirect;
		});
	});

	/* Preview message */
	$(document).on('click', '.ciwa-preview-msg', function () {
		var text = $(this).closest('.ciwa-step-row').find('.ciwa-message').val() || '';
		post('ciwa_preview_message', { message: text }).done(function (res) {
			if (!res.success) return;
			$('#ciwa-preview-body').text(res.data.preview);
			var m = res.data.meta || {};
			$('#ciwa-preview-meta').text((m.chars || 0) + ' کاراکتر — حدود ' + (m.parts || 0) + ' بخش پیامک');
			$('#ciwa-preview-modal').prop('hidden', false);
		});
	});
	$(document).on('click', '#ciwa-preview-close', function () {
		$('#ciwa-preview-modal').prop('hidden', true);
	});

	/* Flows */
	$(document).on('click', '#ciwa-save-flows', function () {
		var $btn = $(this).prop('disabled', true);
		post('ciwa_save_flows', {
			stages: JSON.stringify(collectFlows()),
			cart_abandon_minutes: $('#ciwa-cart-minutes').val()
		}).done(function (res) {
			toast(res.success ? res.data.message : (res.data && res.data.message) || CiwaAdmin.i18n.error, !res.success);
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	/* SMS */
	$(document).on('click', '#ciwa-save-sms', function () {
		var $btn = $(this).prop('disabled', true);
		post('ciwa_save_sms', {
			sms_provider: $('#ciwa-sms-provider').val(),
			sms_api_key: $('#ciwa-api-key').val(),
			from_number: $('#ciwa-from').val(),
			admin_phone: $('#ciwa-admin-phone').val(),
			email_enabled: $('#ciwa-email-enabled').is(':checked') ? 1 : 0
		}).done(function (res) {
			toast(res.success ? res.data.message : (res.data && res.data.message) || CiwaAdmin.i18n.error, !res.success);
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	$(document).on('click', '#ciwa-test-sms', function () {
		var $btn = $(this).prop('disabled', true);
		post('ciwa_test_sms').done(function (res) {
			toast(res.success ? res.data.message : (res.data && res.data.message) || CiwaAdmin.i18n.error, !res.success);
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	$(document).on('click', '#ciwa-save-rules', function () {
		var $btn = $(this).prop('disabled', true);
		post('ciwa_save_rules', {
			quiet_hours_enabled: $('#ciwa-quiet-enabled').is(':checked') ? 1 : 0,
			quiet_hours_start: $('#ciwa-quiet-start').val(),
			quiet_hours_end: $('#ciwa-quiet-end').val(),
			stop_keywords: $('#ciwa-stop-keywords').val(),
			blacklist: $('#ciwa-blacklist').val(),
			min_order_total: $('#ciwa-min-total').val(),
			first_purchase_only: $('#ciwa-first-purchase').is(':checked') ? 1 : 0
		}).done(function (res) {
			toast(res.success ? res.data.message : (res.data && res.data.message) || CiwaAdmin.i18n.error, !res.success);
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	$(document).on('click', '#ciwa-activate-internal', function () {
		var $btn = $(this).prop('disabled', true);
		post('ciwa_activate_internal').done(function (res) {
			if (!res.success) {
				toast((res.data && res.data.message) || CiwaAdmin.i18n.error, true);
				$btn.prop('disabled', false);
				return;
			}
			toast(res.data.message);
			window.location = res.data.redirect;
		}).fail(function () {
			$btn.prop('disabled', false);
		});
	});

	function renderQueue(items) {
		if (!items || !items.length) {
			$('#ciwa-queue-table').html('<p class="ciwa-muted-note">صفی خالی است.</p>');
			return;
		}
		var html = '<table class="ciwa-table"><thead><tr><th>ID</th><th>وضعیت</th><th>سفر</th><th>مرحله</th><th>زمان</th><th>گیرنده</th><th></th></tr></thead><tbody>';
		items.forEach(function (row) {
			html += '<tr>' +
				'<td>' + row.id + '</td>' +
				'<td><span class="ciwa-badge ciwa-badge-' + escapeHtml(row.status === 'sent' ? 'success' : (row.status === 'failed' ? 'error' : 'info')) + '">' + escapeHtml(row.status) + '</span></td>' +
				'<td>' + escapeHtml(row.journey_key) + '</td>' +
				'<td>' + escapeHtml(row.step_id) + '</td>' +
				'<td class="ltr">' + escapeHtml(row.scheduled_at) + '</td>' +
				'<td class="ltr">' + escapeHtml(row.recipient) + '</td>' +
				'<td>' + (row.status === 'failed' ? '<button type="button" class="ciwa-btn ciwa-btn-ghost ciwa-queue-retry" data-id="' + row.id + '">تلاش مجدد</button>' : '') + '</td>' +
				'</tr>';
		});
		html += '</tbody></table>';
		$('#ciwa-queue-table').html(html);
	}

	$(document).on('click', '#ciwa-refresh-queue', function () {
		post('ciwa_queue_query').done(function (res) {
			if (res.success) renderQueue(res.data.items);
		});
	});
	$(document).on('click', '#ciwa-run-queue-now', function () {
		post('ciwa_queue_run').done(function (res) {
			toast(res.success ? res.data.message : CiwaAdmin.i18n.error, !res.success);
			$('#ciwa-refresh-queue').trigger('click');
		});
	});
	$(document).on('click', '.ciwa-queue-retry', function () {
		var id = $(this).data('id');
		post('ciwa_queue_retry', { id: id }).done(function (res) {
			toast(res.success ? res.data.message : CiwaAdmin.i18n.error, !res.success);
			$('#ciwa-refresh-queue').trigger('click');
		});
	});
	if ($('#ciwa-queue-table').length) {
		$('#ciwa-refresh-queue').trigger('click');
	}

	function bindWcKeyButtons(saveBtn, regenBtn, ckInput, csInput, statusEl) {
		$(document).on('click', saveBtn, function () {
			var $btn = $(this).prop('disabled', true);
			post('ciwa_save_wc_keys', {
				wc_ck: $(ckInput).val(),
				wc_cs: $(csInput).val()
			}).done(function (res) {
				var msg = res.success ? res.data.message : ((res.data && res.data.message) || CiwaAdmin.i18n.error);
				toast(msg, !res.success);
				$(statusEl).text(msg).css('color', res.success ? '#2ee59d' : '#ff5c7a');
			}).always(function () {
				$btn.prop('disabled', false);
			});
		});
		$(document).on('click', regenBtn, function () {
			var $btn = $(this).prop('disabled', true);
			post('ciwa_regen_wc_keys').done(function (res) {
				if (!res.success) {
					var err = (res.data && res.data.message) || CiwaAdmin.i18n.error;
					toast(err, true);
					$(statusEl).text(err).css('color', '#ff5c7a');
					return;
				}
				$(ckInput).val(res.data.wc_ck);
				$(csInput).val(res.data.wc_cs);
				toast(res.data.message);
				$(statusEl).text(res.data.message).css('color', '#2ee59d');
			}).always(function () {
				$btn.prop('disabled', false);
			});
		});
	}

	bindWcKeyButtons('#ciwa-setup-wc-save', '#ciwa-setup-wc-regen', '#ciwa-setup-wc-ck', '#ciwa-setup-wc-cs', '#ciwa-setup-wc-status');
	bindWcKeyButtons('#ciwa-wc-keys-save', '#ciwa-wc-keys-regen', '#ciwa-wc-ck', '#ciwa-wc-cs', '#ciwa-wc-keys-status');

	function downloadJsonFile(filename, content) {
		try {
			var blob = new Blob([content], { type: 'application/json;charset=utf-8' });
			var url = window.URL.createObjectURL(blob);
			var a = document.createElement('a');
			a.href = url;
			a.download = filename || 'ciwa-workflow.json';
			document.body.appendChild(a);
			a.click();
			setTimeout(function () {
				window.URL.revokeObjectURL(url);
				a.remove();
			}, 500);
			return true;
		} catch (e) {
			toast('دانلود در مرورگر انجام نشد. محتوا را کپی کنید.', true);
			return false;
		}
	}

	var lastWorkflow = { filename: '', json: '' };

	/* Build flow */
	$(document).on('click', '#ciwa-build-flow', function () {
		var $btn = $(this).prop('disabled', true).text(CiwaAdmin.i18n.saving);
		post('ciwa_build_flow').done(function (res) {
			if (!res.success) {
				toast((res.data && res.data.message) || CiwaAdmin.i18n.error, true);
				var $card = $('#ciwa-wc-keys-card');
				if ($card.length) {
					$('html, body').animate({ scrollTop: $card.offset().top - 40 }, 300);
				}
				return;
			}
			toast(res.data.message);
			lastWorkflow.filename = res.data.filename || 'ciwa-workflow.json';
			lastWorkflow.json = res.data.workflow_json || '';
			$('#ciwa-build-result').prop('hidden', false);
			$('#ciwa-n8n-panel').removeClass('is-locked');
			if (lastWorkflow.json) {
				downloadJsonFile(lastWorkflow.filename, lastWorkflow.json);
			}
		}).always(function () {
			$btn.prop('disabled', false).text('ساخت فلو');
		});
	});

	$(document).on('click', '#ciwa-download-link', function (e) {
		e.preventDefault();
		if (lastWorkflow.json) {
			downloadJsonFile(lastWorkflow.filename, lastWorkflow.json);
			return;
		}
		var $btn = $(this).prop('disabled', true);
		post('ciwa_download_workflow').done(function (res) {
			if (!res.success) {
				toast((res.data && res.data.message) || CiwaAdmin.i18n.error, true);
				return;
			}
			lastWorkflow.filename = res.data.filename;
			lastWorkflow.json = res.data.workflow_json;
			downloadJsonFile(lastWorkflow.filename, lastWorkflow.json);
			toast('دانلود شروع شد');
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	$(document).on('click', '#ciwa-redownload-workflow', function () {
		var $btn = $(this).prop('disabled', true);
		post('ciwa_download_workflow').done(function (res) {
			if (!res.success) {
				toast((res.data && res.data.message) || CiwaAdmin.i18n.error, true);
				return;
			}
			downloadJsonFile(res.data.filename, res.data.workflow_json);
			toast('دانلود شروع شد');
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	$(document).on('click', '#ciwa-factory-reset', function () {
		var confirmText = ($('#ciwa-reset-confirm').val() || '').trim();
		if (confirmText !== 'RESET') {
			toast('برای تأیید، دقیقاً RESET را وارد کنید.', true);
			return;
		}
		if (!window.confirm('همه تنظیمات و دیتای افزونه پاک شود؟ این کار برگشت‌ناپذیر است.')) {
			return;
		}
		var $btn = $(this).prop('disabled', true).text('در حال پاک‌سازی…');
		post('ciwa_factory_reset', { confirm: confirmText }).done(function (res) {
			if (!res.success) {
				toast((res.data && res.data.message) || CiwaAdmin.i18n.error, true);
				$btn.prop('disabled', false).text('پاک کردن تمام اطلاعات افزونه');
				return;
			}
			toast(res.data.message);
			window.location = res.data.redirect;
		}).fail(function () {
			toast(CiwaAdmin.i18n.error, true);
			$btn.prop('disabled', false).text('پاک کردن تمام اطلاعات افزونه');
		});
	});

	/* n8n urls */
	$(document).on('click', '#ciwa-save-n8n', function () {
		post('ciwa_save_n8n', {
			n8n_test_url: $('#ciwa-n8n-test').val(),
			n8n_prod_url: $('#ciwa-n8n-prod').val()
		}).done(function (res) {
			toast(res.success ? res.data.message : (res.data && res.data.message) || CiwaAdmin.i18n.error, !res.success);
			if (res.success) {
				if (res.data.n8n_test_url) $('#ciwa-n8n-test').val(res.data.n8n_test_url);
				if (res.data.n8n_prod_url) $('#ciwa-n8n-prod').val(res.data.n8n_prod_url);
			}
		});
	});

	$(document).on('click', '#ciwa-test-conn', function () {
		var $btn = $(this).prop('disabled', true).text(CiwaAdmin.i18n.testing);
		post('ciwa_test_connection', {
			n8n_test_url: $('#ciwa-n8n-test').val()
		}).done(function (res) {
			if (!res.success) {
				toast((res.data && res.data.message) || 'اتصال برقرار نشد. لطفاً از صحت آدرس وارد شده و یا روشن بودن سرویس n8n اطمینان حاصل کنید.', true);
				return;
			}
			if (res.data.n8n_test_url) {
				$('#ciwa-n8n-test').val(res.data.n8n_test_url);
			}
			renderReport(res.data.report);
			toast(res.data.report.ok ? 'اتصال برقرار شد' : res.data.report.summary, !res.data.report.ok);
		}).fail(function () {
			toast('اتصال برقرار نشد. لطفاً از صحت آدرس وارد شده و یا روشن بودن سرویس n8n اطمینان حاصل کنید.', true);
		}).always(function () {
			$btn.prop('disabled', false).text('تست اتصال');
		});
	});

	$(document).on('click', '#ciwa-activate-prod', function () {
		var $btn = $(this).prop('disabled', true);
		post('ciwa_activate_prod', {
			n8n_prod_url: $('#ciwa-n8n-prod').val()
		}).done(function (res) {
			if (!res.success) {
				toast((res.data && res.data.message) || CiwaAdmin.i18n.error, true);
				$btn.prop('disabled', false);
				return;
			}
			toast(res.data.message);
			window.location = res.data.redirect;
		}).fail(function () {
			$btn.prop('disabled', false);
		});
	});

	$(document).on('click', '#ciwa-deactivate', function () {
		if (!window.confirm('اتومیشن متوقف شود؟')) return;
		post('ciwa_deactivate').done(function (res) {
			toast(res.success ? res.data.message : CiwaAdmin.i18n.error, !res.success);
			if (res.success) location.reload();
		});
	});

	/* Settings tab quick save */
	$(document).on('click', '#ciwa-settings-save', function () {
		var jobs = [
			post('ciwa_save_sms', {
				sms_provider: $('#ciwa-set-provider').val(),
				sms_api_key: $('#ciwa-set-key').val(),
				from_number: $('#ciwa-set-from').val(),
				admin_phone: $('#ciwa-set-phone').val()
			})
		];
		if ($('#ciwa-set-test').length) {
			jobs.unshift(post('ciwa_save_n8n', {
				n8n_test_url: $('#ciwa-set-test').val(),
				n8n_prod_url: $('#ciwa-set-prod').val()
			}));
		}
		$.when.apply($, jobs).done(function () {
			toast('تنظیمات ذخیره شد');
		});
	});

	$(document).on('click', '#ciwa-set-mode-internal, #ciwa-set-mode-n8n', function () {
		var mode = $(this).data('mode');
		if (!window.confirm('تغییر موتور ممکن است نیاز به پیکربندی مجدد داشته باشد. ادامه؟')) return;
		post('ciwa_choose_engine', { mode: mode }).done(function (res) {
			toast(res.success ? res.data.message : CiwaAdmin.i18n.error, !res.success);
			if (res.success) location.reload();
		});
	});

	/* Logs */
	var logPage = 1;

	function loadLogs() {
		post('ciwa_logs_query', {
			page: logPage,
			level: $('#ciwa-log-level').val(),
			channel: $('#ciwa-log-channel').val(),
			search: $('#ciwa-log-search').val()
		}).done(function (res) {
			if (!res.success) return;
			var rows = '';
			(res.data.items || []).forEach(function (item) {
				rows += '<tr>' +
					'<td><input type="checkbox" class="ciwa-log-id" value="' + item.id + '" /></td>' +
					'<td>' + escapeHtml(item.created_at) + '</td>' +
					'<td><span class="ciwa-badge ciwa-badge-' + escapeHtml(item.level) + '">' + escapeHtml(item.level) + '</span></td>' +
					'<td>' + escapeHtml(item.channel) + '</td>' +
					'<td>' + escapeHtml(item.message) + '</td>' +
					'<td><code class="ltr">' + escapeHtml((item.context || '').slice(0, 120)) + '</code></td>' +
					'</tr>';
			});
			$('#ciwa-logs-table tbody').html(rows || '<tr><td colspan="6">لاگی نیست.</td></tr>');
			$('#ciwa-log-page-info').text('صفحه ' + res.data.page + ' از ' + Math.max(1, res.data.pages) + ' — جمع ' + res.data.total);
			logPage = res.data.page;
		});
	}

	if ($('#ciwa-logs-table').length) {
		loadLogs();
	}

	$(document).on('click', '#ciwa-log-refresh', function () {
		logPage = 1;
		loadLogs();
	});
	$(document).on('click', '#ciwa-log-prev', function () {
		logPage = Math.max(1, logPage - 1);
		loadLogs();
	});
	$(document).on('click', '#ciwa-log-next', function () {
		logPage += 1;
		loadLogs();
	});
	$(document).on('change', '#ciwa-log-check-all', function () {
		$('.ciwa-log-id').prop('checked', this.checked);
	});
	$(document).on('click', '#ciwa-log-delete', function () {
		var ids = $('.ciwa-log-id:checked').map(function () { return this.value; }).get();
		if (!ids.length) {
			toast('موردی انتخاب نشده', true);
			return;
		}
		post('ciwa_logs_delete', { ids: ids }).done(function () {
			loadLogs();
			toast('حذف شد');
		});
	});
	$(document).on('click', '#ciwa-log-clear-old', function () {
		post('ciwa_logs_clear', { mode: 'old' }).done(function () {
			loadLogs();
			toast('لاگ‌های قدیمی پاک شد');
		});
	});
	$(document).on('click', '#ciwa-log-clear-all', function () {
		if (!window.confirm(CiwaAdmin.i18n.confirmClear)) return;
		post('ciwa_logs_clear', { mode: 'all' }).done(function () {
			loadLogs();
			toast('همه لاگ‌ها پاک شد');
		});
	});

	/* Charts */
	function initCharts() {
		var el = document.getElementById('ciwa-dashboard-data');
		if (!el || typeof Chart === 'undefined') return;
		var data = JSON.parse(el.textContent || '{}');
		var common = {
			responsive: true,
			plugins: { legend: { labels: { color: '#5f7b86', font: { family: 'Vazirmatn' } } } },
			scales: {
				x: { ticks: { color: '#5f7b86' }, grid: { color: 'rgba(15, 60, 80, 0.08)' } },
				y: { ticks: { color: '#5f7b86' }, grid: { color: 'rgba(15, 60, 80, 0.08)' }, beginAtZero: true }
			}
		};
		if (document.getElementById('ciwa-chart-orders') && data.orders) {
			new Chart(document.getElementById('ciwa-chart-orders'), {
				type: 'line',
				data: {
					labels: data.orders.labels,
					datasets: [
						{ label: 'موفق', data: data.orders.paid, borderColor: '#2fd66f', backgroundColor: 'rgba(47,214,111,0.18)', tension: 0.35, fill: true },
						{ label: 'ناموفق', data: data.orders.failed, borderColor: '#e5486a', backgroundColor: 'rgba(229,72,106,0.12)', tension: 0.35, fill: true }
					]
				},
				options: common
			});
		}
		if (document.getElementById('ciwa-chart-logs') && data.logs) {
			new Chart(document.getElementById('ciwa-chart-logs'), {
				type: 'bar',
				data: {
					labels: data.logs.labels,
					datasets: [{
						label: 'رویداد',
						data: data.logs.counts,
						backgroundColor: 'rgba(30,167,196,0.55)',
						borderRadius: 8
					}]
				},
				options: common
			});
		}
	}

	$(initCharts);
})(jQuery);
