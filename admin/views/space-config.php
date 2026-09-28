<?php
/** Variables supplied by AdminMenu::render_space_config(). */

if ( ! defined( 'WPINC' ) ) {
	die;
}

$spaces_json  = wp_json_encode( $spaces );
$configs_json = wp_json_encode( (object) $configs );
?>
<div class="wrap appza-space-config">
	<h1><?php esc_html_e( 'Space Config', 'appza-core' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Per-space runtime overrides for the Space Detail screen (switcher, subtabs, cover, topics). Edits save immediately and take effect on the next mobile fetch.', 'appza-core' ); ?>
	</p>

	<div class="appza-sc-toolbar">
		<label for="appza-sc-space-picker"><strong><?php esc_html_e( 'Space:', 'appza-core' ); ?></strong></label>
		<select id="appza-sc-space-picker" style="min-width: 280px;"></select>
		<button type="button" class="button button-primary" id="appza-sc-save-btn"><?php esc_html_e( 'Save', 'appza-core' ); ?></button>
		<button type="button" class="button" id="appza-sc-reset-btn"><?php esc_html_e( 'Reset to server', 'appza-core' ); ?></button>
		<button type="button" class="button button-link-delete" id="appza-sc-delete-btn"><?php esc_html_e( 'Clear all overrides', 'appza-core' ); ?></button>
		<span id="appza-sc-status" aria-live="polite" style="margin-left: 12px; color: #2271b1;"></span>
	</div>

	<div class="appza-sc-grid">
		<!-- SWITCHER -->
		<section class="appza-sc-card">
			<h2><?php esc_html_e( 'Switcher', 'appza-core' ); ?></h2>
			<p><label><input type="checkbox" data-sc-path="switcher.hidden"> <?php esc_html_e( 'Hide switcher strip', 'appza-core' ); ?></label></p>
			<p>
				<label><strong><?php esc_html_e( 'Spaces whitelist', 'appza-core' ); ?></strong></label><br>
				<em class="description"><?php esc_html_e( 'Hold ⌘/Ctrl to pick multiple. Empty = show all spaces.', 'appza-core' ); ?></em><br>
				<select data-sc-path="switcher.spaces_whitelist" multiple size="6" style="width: 100%;" id="appza-sc-whitelist"></select>
			</p>
			<fieldset>
				<legend><strong><?php esc_html_e( 'Style', 'appza-core' ); ?></strong></legend>
				<p><label><?php esc_html_e( 'Gap', 'appza-core' ); ?><br><input type="text" data-sc-path="switcher._style.gap" placeholder="8px"></label></p>
				<p><label><?php esc_html_e( 'Chip radius', 'appza-core' ); ?><br><input type="text" data-sc-path="switcher._style.chip_radius" placeholder="8px"></label></p>
				<p><label><?php esc_html_e( 'Chip bg color', 'appza-core' ); ?><br><input type="text" data-sc-path="switcher._style.chip_bg_color" placeholder="#ffffff"></label></p>
				<p><label><?php esc_html_e( 'Chip text color', 'appza-core' ); ?><br><input type="text" data-sc-path="switcher._style.chip_text_color" placeholder="#374151"></label></p>
				<p><label><?php esc_html_e( 'Active bg color', 'appza-core' ); ?><br><input type="text" data-sc-path="switcher._style.active_bg_color" placeholder="#2563eb"></label></p>
				<p><label><?php esc_html_e( 'Active text color', 'appza-core' ); ?><br><input type="text" data-sc-path="switcher._style.active_text_color" placeholder="#ffffff"></label></p>
			</fieldset>
		</section>

		<!-- SUBTABS -->
		<section class="appza-sc-card">
			<h2><?php esc_html_e( 'Subtabs', 'appza-core' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Full replace: the tab list below is used verbatim. Reorder / hide / add / remove rows. target_screen turns a tap into a deep-link nav.push to another screen instead of switching sub-tabs.', 'appza-core' ); ?></p>
			<table class="widefat" id="appza-sc-subtabs-table">
				<thead>
					<tr>
						<th style="width: 26px;"></th>
						<th><?php esc_html_e( 'value', 'appza-core' ); ?></th>
						<th><?php esc_html_e( 'label', 'appza-core' ); ?></th>
						<th><?php esc_html_e( 'icon', 'appza-core' ); ?></th>
						<th><?php esc_html_e( 'target_screen', 'appza-core' ); ?></th>
						<th><?php esc_html_e( 'hidden', 'appza-core' ); ?></th>
						<th style="width: 60px;"></th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
			<p><button type="button" class="button" id="appza-sc-subtab-add"><?php esc_html_e( '+ Add row', 'appza-core' ); ?></button></p>
		</section>

		<!-- TOPICS -->
		<section class="appza-sc-card">
			<h2><?php esc_html_e( 'Topics', 'appza-core' ); ?></h2>
			<p><label><?php esc_html_e( 'Section header', 'appza-core' ); ?><br><input type="text" data-sc-path="topics.section_header" placeholder="Choose topic"></label></p>
			<p><label><?php esc_html_e( 'All-chip label', 'appza-core' ); ?><br><input type="text" data-sc-path="topics.all_label" placeholder="All"></label></p>
			<p><label><input type="checkbox" data-sc-path="topics.prepend_all"> <?php esc_html_e( 'Prepend "All" chip', 'appza-core' ); ?></label></p>
			<p>
				<label><?php esc_html_e( 'Topic order (comma-separated slugs; unlisted keep original order behind these)', 'appza-core' ); ?></label>
				<input type="text" data-sc-path="topics.topic_order" data-sc-list="1" style="width: 100%;" placeholder="dev,design">
			</p>
			<p>
				<label><?php esc_html_e( 'Hidden topics (comma-separated slugs)', 'appza-core' ); ?></label>
				<input type="text" data-sc-path="topics.hidden_topics" data-sc-list="1" style="width: 100%;" placeholder="business">
			</p>
			<fieldset>
				<legend><strong><?php esc_html_e( 'Style', 'appza-core' ); ?></strong></legend>
				<p><label><?php esc_html_e( 'Gap', 'appza-core' ); ?><br><input type="text" data-sc-path="topics._style.gap" placeholder="6px"></label></p>
				<p><label><?php esc_html_e( 'Chip radius', 'appza-core' ); ?><br><input type="text" data-sc-path="topics._style.chip_radius" placeholder="6px"></label></p>
				<p><label><?php esc_html_e( 'Active border color', 'appza-core' ); ?><br><input type="text" data-sc-path="topics._style.active_border_color" placeholder="#1d4ed8"></label></p>
				<p><label><?php esc_html_e( 'Font weight', 'appza-core' ); ?><br><input type="text" data-sc-path="topics._style.font_weight" placeholder="500"></label></p>
			</fieldset>
		</section>

		<!-- COVER -->
		<section class="appza-sc-card">
			<h2><?php esc_html_e( 'Cover', 'appza-core' ); ?></h2>
			<p><label><input type="checkbox" data-sc-path="cover.hidden"> <?php esc_html_e( 'Hide cover on this space', 'appza-core' ); ?></label></p>
			<p><label><?php esc_html_e( 'Height', 'appza-core' ); ?><br><input type="text" data-sc-path="cover.height" placeholder="220px"></label></p>
			<p><label><?php esc_html_e( 'Aspect ratio', 'appza-core' ); ?><br><input type="text" data-sc-path="cover.aspect_ratio" placeholder="16 / 9"></label></p>
			<p><label><?php esc_html_e( 'Overlay title', 'appza-core' ); ?><br><input type="text" data-sc-path="cover.overlay_title" placeholder=""></label></p>
			<p><label><?php esc_html_e( 'Overlay gradient (CSS)', 'appza-core' ); ?><br><input type="text" data-sc-path="cover.overlay_gradient" placeholder="linear-gradient(180deg, transparent, rgba(0,0,0,0.55))"></label></p>
		</section>
	</div>

	<details style="margin-top: 24px;">
		<summary><?php esc_html_e( 'Raw JSON (advanced)', 'appza-core' ); ?></summary>
		<textarea id="appza-sc-raw-json" rows="14" style="width: 100%; font-family: monospace; font-size: 12px;"></textarea>
		<p><button type="button" class="button" id="appza-sc-apply-raw"><?php esc_html_e( 'Apply raw JSON to form', 'appza-core' ); ?></button></p>
	</details>
</div>

<style>
.appza-sc-toolbar { margin: 12px 0 16px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.appza-sc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 16px; }
.appza-sc-card { background: #fff; padding: 16px; border: 1px solid #dcdcde; border-radius: 4px; }
.appza-sc-card h2 { margin-top: 0; font-size: 16px; }
.appza-sc-card fieldset { border: 1px solid #dcdcde; padding: 8px 12px; border-radius: 4px; margin-top: 12px; }
.appza-sc-card fieldset legend { padding: 0 6px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.03em; color: #50575e; }
.appza-sc-card p { margin: 8px 0; }
.appza-sc-card label { display: block; font-size: 13px; }
.appza-sc-card input[type="text"] { width: 100%; }
#appza-sc-subtabs-table td { vertical-align: middle; }
#appza-sc-subtabs-table input[type="text"] { width: 100%; }
.appza-sc-drag { cursor: grab; user-select: none; color: #a7aaad; }
.appza-sc-status-ok { color: #00a32a; }
.appza-sc-status-err { color: #d63638; }
</style>

<script>
(function () {
	var SPACES = <?php echo $spaces_json ?: '[]'; ?>;
	var CONFIGS = <?php echo $configs_json ?: '{}'; ?>;
	var REST_ROOT = <?php echo wp_json_encode( $rest_root ); ?>;
	var NONCE = <?php echo wp_json_encode( $nonce ); ?>;

	// state.current = { slug, config }
	var state = { slug: (SPACES[0] && SPACES[0].slug) || '', config: {}, serverConfig: {} };

	function $(sel, root) { return (root || document).querySelector(sel); }
	function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

	function getPath(obj, path) {
		var parts = path.split('.'), cur = obj;
		for (var i = 0; i < parts.length; i++) {
			if (cur == null || typeof cur !== 'object') return undefined;
			cur = cur[parts[i]];
		}
		return cur;
	}
	function setPath(obj, path, val) {
		var parts = path.split('.'), cur = obj;
		for (var i = 0; i < parts.length - 1; i++) {
			var k = parts[i];
			if (cur[k] == null || typeof cur[k] !== 'object') cur[k] = {};
			cur = cur[k];
		}
		if (val === '' || val === undefined || val === null ||
			(Array.isArray(val) && val.length === 0)) {
			delete cur[parts[parts.length - 1]];
		} else {
			cur[parts[parts.length - 1]] = val;
		}
	}
	function pruneEmpty(o) {
		if (o == null || typeof o !== 'object' || Array.isArray(o)) return o;
		Object.keys(o).forEach(function (k) {
			var v = o[k];
			if (v && typeof v === 'object' && !Array.isArray(v)) {
				pruneEmpty(v);
				if (Object.keys(v).length === 0) delete o[k];
			}
		});
		return o;
	}

	function populatePicker() {
		var sel = $('#appza-sc-space-picker');
		sel.innerHTML = SPACES.map(function (s) {
			var badge = CONFIGS[s.slug] && Object.keys(CONFIGS[s.slug]).length ? ' •' : '';
			return '<option value="' + s.slug + '">' + s.title + ' (' + s.slug + ')' + badge + '</option>';
		}).join('');
		sel.value = state.slug;
	}
	function populateWhitelist() {
		var sel = $('#appza-sc-whitelist');
		sel.innerHTML = SPACES.map(function (s) {
			return '<option value="' + s.slug + '">' + s.title + ' (' + s.slug + ')</option>';
		}).join('');
	}

	function renderSubtabsRow(row, idx) {
		return (
			'<tr data-idx="' + idx + '">' +
			'<td class="appza-sc-drag" draggable="true" title="Drag to reorder">⋮⋮</td>' +
			'<td><input type="text" data-field="value" value="' + (row.value || '') + '"></td>' +
			'<td><input type="text" data-field="label" value="' + (row.label || '') + '"></td>' +
			'<td><input type="text" data-field="icon" value="' + (row.icon || '') + '"></td>' +
			'<td><input type="text" data-field="target_screen" value="' + (row.target_screen || '') + '"></td>' +
			'<td style="text-align:center;"><input type="checkbox" data-field="hidden"' + (row.hidden ? ' checked' : '') + '></td>' +
			'<td><button type="button" class="button-link-delete" data-action="remove">✕</button></td>' +
			'</tr>'
		);
	}
	function renderSubtabs() {
		var rows = Array.isArray(state.config.subtabs) ? state.config.subtabs : [];
		$('#appza-sc-subtabs-table tbody').innerHTML = rows.map(renderSubtabsRow).join('') ||
			'<tr><td colspan="7"><em>No subtabs override — falls back to POSTS / MEMBERS / DOCUMENTS / CHAT.</em></td></tr>';
	}

	function paintForm() {
		$$('[data-sc-path]').forEach(function (el) {
			var path = el.getAttribute('data-sc-path');
			var isList = el.getAttribute('data-sc-list') === '1';
			var v = getPath(state.config, path);
			if (el.type === 'checkbox') {
				el.checked = !!v;
			} else if (el.multiple) {
				var arr = Array.isArray(v) ? v : [];
				$$('option', el).forEach(function (o) { o.selected = arr.indexOf(o.value) !== -1; });
			} else {
				el.value = isList
					? (Array.isArray(v) ? v.join(',') : '')
					: (v == null ? '' : String(v));
			}
		});
		renderSubtabs();
		$('#appza-sc-raw-json').value = JSON.stringify(state.config, null, 2);
	}

	function readForm() {
		$$('[data-sc-path]').forEach(function (el) {
			var path = el.getAttribute('data-sc-path');
			var isList = el.getAttribute('data-sc-list') === '1';
			var v;
			if (el.type === 'checkbox') {
				v = el.checked;
				if (!v) { setPath(state.config, path, undefined); return; }
			} else if (el.multiple) {
				v = $$('option', el).filter(function (o) { return o.selected; }).map(function (o) { return o.value; });
			} else {
				v = el.value;
				if (isList) v = v.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
			}
			setPath(state.config, path, v);
		});
		// Subtabs table
		var rows = $$('#appza-sc-subtabs-table tbody tr[data-idx]').map(function (tr) {
			var o = {};
			$$('[data-field]', tr).forEach(function (el) {
				var f = el.getAttribute('data-field');
				o[f] = el.type === 'checkbox' ? el.checked : el.value;
			});
			return o;
		}).filter(function (r) { return r.value && r.value.trim() !== ''; });
		if (rows.length) state.config.subtabs = rows;
		else delete state.config.subtabs;
		pruneEmpty(state.config);
	}

	function setStatus(msg, cls) {
		var el = $('#appza-sc-status');
		el.textContent = msg;
		el.className = cls || '';
	}

	function loadSpace(slug) {
		state.slug = slug;
		var stored = CONFIGS[slug];
		state.serverConfig = JSON.parse(JSON.stringify(stored || {}));
		state.config = JSON.parse(JSON.stringify(stored || {}));
		paintForm();
		setStatus('');
	}

	function save() {
		readForm();
		setStatus('Saving…');
		fetch(REST_ROOT + '/' + encodeURIComponent(state.slug), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
			body: JSON.stringify({ config: state.config }),
		})
			.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
			.then(function (res) {
				if (!res.ok) throw new Error((res.body && res.body.message) || 'Save failed');
				CONFIGS[state.slug] = res.body.config && Object.keys(res.body.config).length
					? res.body.config : {};
				state.serverConfig = JSON.parse(JSON.stringify(CONFIGS[state.slug]));
				populatePicker();
				setStatus('Saved.', 'appza-sc-status-ok');
			})
			.catch(function (err) { setStatus(err.message || 'Save failed', 'appza-sc-status-err'); });
	}

	function clearAll() {
		if (!confirm('Remove all runtime overrides for ' + state.slug + '?')) return;
		state.config = {};
		save();
	}

	// Wire event handlers
	document.addEventListener('DOMContentLoaded', function () {
		populatePicker();
		populateWhitelist();
		loadSpace(state.slug);

		$('#appza-sc-space-picker').addEventListener('change', function (e) { loadSpace(e.target.value); });
		$('#appza-sc-save-btn').addEventListener('click', save);
		$('#appza-sc-reset-btn').addEventListener('click', function () { loadSpace(state.slug); });
		$('#appza-sc-delete-btn').addEventListener('click', clearAll);
		$('#appza-sc-subtab-add').addEventListener('click', function () {
			readForm();
			if (!Array.isArray(state.config.subtabs)) state.config.subtabs = [];
			state.config.subtabs.push({ value: '', label: '' });
			renderSubtabs();
		});
		$('#appza-sc-subtabs-table').addEventListener('click', function (e) {
			if (e.target.getAttribute('data-action') !== 'remove') return;
			var tr = e.target.closest('tr[data-idx]');
			if (!tr) return;
			readForm();
			var idx = Number(tr.getAttribute('data-idx'));
			state.config.subtabs.splice(idx, 1);
			renderSubtabs();
		});
		$('#appza-sc-apply-raw').addEventListener('click', function () {
			try {
				var parsed = JSON.parse($('#appza-sc-raw-json').value);
				if (!parsed || typeof parsed !== 'object') throw new Error('Not an object');
				state.config = parsed;
				paintForm();
				setStatus('Parsed. Click Save to persist.');
			} catch (err) {
				setStatus('Invalid JSON: ' + err.message, 'appza-sc-status-err');
			}
		});
	});
})();
</script>
