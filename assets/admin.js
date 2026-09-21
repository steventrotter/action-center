(function ($) {
	'use strict';

	// Counter for unique editor IDs
	var editorCounter = 0;

	// -------- Steps: rich text repeaters --------

	function initStepsRepeater() {
		var wrapper = $('#cta-steps-wrapper');
		if (!wrapper.length) {
			return;
		}

		$('#cta-add-step').on('click', function (e) {
			e.preventDefault();

			var count = wrapper.find('.cta-step-item').length + 1;
			editorCounter++;
			var editorId = 'cta_step_editor_' + editorCounter + '_' + Date.now();

			var $item = $('<div class="cta-step-item"></div>');

			var html = '<p><strong>Step ' + count + '</strong></p>' +
				'<div class="cta-step-editor-wrapper">' +
				'<textarea id="' + editorId + '" name="cta_steps[]" rows="8" class="cta-step-textarea"></textarea>' +
				'</div>' +
				'<p><button type="button" class="button cta-remove-step">Remove Step</button></p>';

			$item.html(html);
			wrapper.append($item);

			setTimeout(function() {
				initializeTinyMCE(editorId, 250);
			}, 100);
		});

		wrapper.on('click', '.cta-remove-step', function (e) {
			e.preventDefault();
			var $stepItem = $(this).closest('.cta-step-item');
			var $textarea = $stepItem.find('textarea[name="cta_steps[]"]');
			var editorId = $textarea.attr('id');

			if (editorId && typeof tinymce !== 'undefined') {
				var editor = tinymce.get(editorId);
				if (editor) {
					editor.remove();
				}
			}

			$stepItem.remove();

			wrapper.find('.cta-step-item').each(function (i) {
				$(this).find('p strong').first().text('Step ' + (i + 1));
			});
		});
	}

	function initializeTinyMCE(editorId, height) {
		if (typeof tinymce === 'undefined' || typeof wp === 'undefined' || typeof wp.editor === 'undefined') {
			console.error('TinyMCE or wp.editor not available');
			return;
		}

		var existingEditor = tinymce.get(editorId);
		if (existingEditor) {
			existingEditor.remove();
		}

		height = height || 250;

		wp.editor.initialize(editorId, {
			tinymce: {
				wpautop: true,
				plugins: 'lists,link,paste,textcolor,wordpress,wplink',
				toolbar1: 'bold,italic,underline,bullist,numlist,link,unlink',
				toolbar2: '',
				height: height,
				menubar: false,
				branding: false,
				elementpath: false,
				resize: true,
				convert_urls: false
			},
			quicktags: false,
			mediaButtons: false
		});
	}

	// -------- Shared: make a wrapper sortable --------

	function initSortable(wrapperId) {
		var $wrapper = $(wrapperId);
		if (!$wrapper.length || !$.fn.sortable) {
			return;
		}
		$wrapper.sortable({
			handle: '.cta-drag-handle',
			axis: 'y',
			placeholder: 'cta-sortable-placeholder',
			forcePlaceholderSize: true
		});
	}

	// -------- Related Links repeater --------

	function initLinksRepeater() {
		var wrapper = $('#cta-links-wrapper');
		if (!wrapper.length) {
			return;
		}

		initSortable('#cta-links-wrapper');

		$('#cta-add-link').on('click', function (e) {
			e.preventDefault();
			var $row = $(
				'<div class="cta-link-row cta-sortable-row">' +
					'<span class="cta-drag-handle dashicons dashicons-move" title="Drag to reorder"></span>' +
					'<div class="cta-sortable-fields">' +
						'<input type="text" name="cta_links_url[]" placeholder="https://..." class="cta-link-url">' +
						'<input type="text" name="cta_links_label[]" placeholder="Display name (optional)" class="cta-link-label">' +
					'</div>' +
					'<button type="button" class="button cta-remove-link">Remove</button>' +
				'</div>'
			);
			wrapper.append($row);
		});

		wrapper.on('click', '.cta-remove-link', function (e) {
			e.preventDefault();
			$(this).closest('.cta-link-row').remove();
		});
	}

	// -------- Related Files (media frame) --------

	function initFilesField() {
		var wrapper = $('#cta-files-wrapper');
		var addBtn  = $('#cta-add-file');
		if (!wrapper.length || !addBtn.length || typeof wp === 'undefined' || !wp.media) {
			return;
		}

		initSortable('#cta-files-wrapper');

		var frame;

		addBtn.on('click', function (e) {
			e.preventDefault();

			if (frame) {
				frame.open();
				return;
			}

			frame = wp.media({
				title: 'Select or Upload Related Files',
				button: { text: 'Use these files' },
				multiple: true
			});

			frame.on('select', function () {
				var selection = frame.state().get('selection');
				selection.each(function (attachment) {
					attachment = attachment.toJSON();
					var id    = attachment.id;
					var title = attachment.title || attachment.filename;
					var url   = attachment.url;

					var html =
						'<div class="cta-file-row cta-sortable-row" data-id="' + id + '">' +
							'<span class="cta-drag-handle dashicons dashicons-move" title="Drag to reorder"></span>' +
							'<div class="cta-sortable-fields">' +
								'<input type="hidden" name="cta_files_id[]" value="' + id + '">' +
								'<span class="cta-file-row__label">' + $('<span>').text(title).html() + '</span> ' +
								'<a href="' + url + '" target="_blank" rel="noopener noreferrer">View</a>' +
								'<input type="text" name="cta_files_label[]" placeholder="Display name (optional)" class="cta-file-label">' +
							'</div>' +
							'<button type="button" class="button cta-remove-file">Remove</button>' +
						'</div>';

					wrapper.append(html);
				});
			});

			frame.open();
		});

		wrapper.on('click', '.cta-remove-file', function (e) {
			e.preventDefault();
			$(this).closest('.cta-file-row').remove();
		});
	}

	// -------- Related Videos repeater --------

	function initVideosRepeater() {
		var wrapper = $('#cta-videos-wrapper');
		if (!wrapper.length) {
			return;
		}

		initSortable('#cta-videos-wrapper');

		$('#cta-add-video').on('click', function (e) {
			e.preventDefault();
			var $row = $(
				'<div class="cta-video-row cta-sortable-row">' +
					'<span class="cta-drag-handle dashicons dashicons-move" title="Drag to reorder"></span>' +
					'<div class="cta-sortable-fields">' +
						'<input type="text" name="cta_videos_url[]" placeholder="https://www.youtube.com/watch?v=..." class="cta-video-url">' +
						'<input type="text" name="cta_videos_label[]" placeholder="Display name (optional)" class="cta-video-label">' +
					'</div>' +
					'<button type="button" class="button cta-remove-video">Remove</button>' +
				'</div>'
			);
			wrapper.append($row);
		});

		wrapper.on('click', '.cta-remove-video', function (e) {
			e.preventDefault();
			$(this).closest('.cta-video-row').remove();
		});
	}

	// -------- Sample Text repeater --------

	function initSampleTextsRepeater() {
		var wrapper = $('#cta-sample-texts-wrapper');
		if (!wrapper.length) {
			return;
		}

		$('#cta-add-sample-text').on('click', function (e) {
			e.preventDefault();

			var count = wrapper.find('.cta-sample-text-item').length + 1;

			var $item = $(
				'<div class="cta-sample-text-item" style="margin-bottom:1.5rem;border:1px solid #ddd;padding:0.75rem 0.75rem 1rem;background:#fafafa;">' +
					'<p><strong>Option ' + count + '</strong></p>' +
					'<textarea name="cta_sample_texts[]" rows="6" style="width:100%;"></textarea>' +
					'<p><button type="button" class="button cta-remove-sample-text">Remove Option</button></p>' +
				'</div>'
			);

			wrapper.append($item);
		});

		wrapper.on('click', '.cta-remove-sample-text', function (e) {
			e.preventDefault();
			$(this).closest('.cta-sample-text-item').remove();

			wrapper.find('.cta-sample-text-item').each(function (i) {
				$(this).find('p strong').first().text('Option ' + (i + 1));
			});
		});
	}

	// -------- Action Format: reconfigure the form when Simple/Guided changes --------

	function initFormatToggle() {
		var $sel = $('#cta_format');
		if (!$sel.length) {
			return;
		}
		var $guided = $('#cta_guided_box');
		var $sample = $('#cta_sample_text_box');
		var $stepsTitle = $('#cta_steps_box').find('.hndle, .postbox-header .hndle, h2.hndle').first();
		var $stepsHelp = $('#cta-steps-help');

		function sync() {
			var isGuided = $sel.val() === 'guided';
			$guided.toggle(isGuided);
			// Guided replaces the static Sample Text step, so hide it in Guided mode.
			$sample.toggle(!isGuided);

			// The Steps box means different things in each format.
			if ($stepsTitle.length) {
				$stepsTitle.text(isGuided ? 'Additional Steps (after submitting)' : 'Steps to Take');
			}
			if ($stepsHelp.length) {
				$stepsHelp.text(isGuided
					? 'For a Guided action, these show on the second screen after the supporter builds and submits their comment, under "More Ways to Help." Leave blank for none.'
					: 'For a Simple action, these steps are the action: they walk a supporter through what to do.');
			}
		}

		$sel.on('change', sync);
		sync();
	}

	// -------- Legislator URL: only when the Contact Your Legislator type is chosen --------

	function initLegislatorToggle() {
		var $box = $('#cta_legislator_box');
		if (!$box.length) {
			return;
		}
		var $list = $('#cta_typechecklist');

		function isTypeChecked(name) {
			var found = false;
			$list.find('label').each(function () {
				if ($.trim($(this).text()).toLowerCase() === name) {
					if ($(this).find('input[type="checkbox"]').prop('checked')) {
						found = true;
					}
				}
			});
			return found;
		}

		function sync() {
			$box.toggle(isTypeChecked('contact your legislator'));
		}

		$list.on('change', 'input[type="checkbox"]', sync);
		sync();
	}

	// -------- Before form submit: sync all TinyMCE editors --------

	function syncAllEditors() {
		if (typeof tinymce !== 'undefined') {
			tinymce.triggerSave();
		}
	}

	$(document).ready(function () {
		initStepsRepeater();
		initLinksRepeater();
		initFilesField();
		initVideosRepeater();
		initSampleTextsRepeater();
		initFormatToggle();
		initLegislatorToggle();

		$('form#post').on('submit', function() {
			syncAllEditors();
		});
	});

})(jQuery);

/**
 * Deadline box: toggle the deadline/ended rows when "Ongoing" changes.
 * Moved out of an inline <script> in the Deadline meta box so it is enqueued.
 */
(function() {
	var cb          = document.getElementById('cta_ongoing');
	var deadlineRow = document.getElementById('cta-deadline-row');
	var endedRow    = document.getElementById('cta-ended-row');
	if (!cb) { return; }
	cb.addEventListener('change', function() {
		if (deadlineRow) { deadlineRow.style.display = cb.checked ? 'none' : ''; }
		if (endedRow)    { endedRow.style.display    = cb.checked ? '' : 'none'; }
	});
})();

/**
 * Guided Comment Builder box: add/remove talking points and personal prompts.
 * Moved out of an inline <script> in that meta box so it is enqueued.
 */
(function() {
	var tpWrap = document.getElementById('cta-tp-wrapper');
	var tpTpl  = document.getElementById('cta-tp-template');
	var addTp  = document.getElementById('cta-add-tp');
	if (addTp) { addTp.addEventListener('click', function() { tpWrap.insertAdjacentHTML('beforeend', tpTpl.innerHTML); }); }
	if (tpWrap) { tpWrap.addEventListener('click', function(e) { if (e.target.classList.contains('cta-remove-tp')) { var r = e.target.closest('.cta-tp-row'); if (r) { r.remove(); } } }); }

	var ppWrap = document.getElementById('cta-pp-wrapper');
	var addPp  = document.getElementById('cta-add-pp');
	if (addPp) { addPp.addEventListener('click', function() {
		var d = document.createElement('div');
		d.className = 'cta-pp-row';
		d.style.marginBottom = '0.5rem';
		d.innerHTML = '<input type="text" name="cta_personal_prompts[]" value="" style="width:calc(100% - 90px);" placeholder="Why does this place matter to you?"> <button type="button" class="button cta-remove-pp">Remove</button>';
		ppWrap.appendChild(d);
	}); }
	if (ppWrap) { ppWrap.addEventListener('click', function(e) { if (e.target.classList.contains('cta-remove-pp')) { var r = e.target.closest('.cta-pp-row'); if (r) { r.remove(); } } }); }
})();

